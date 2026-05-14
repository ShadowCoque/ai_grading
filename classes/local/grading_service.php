<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Application service for the teacher setup interface.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Coordinates Moodle DB persistence and VPL data for the first teacher interface.
 */
class grading_service {
    /**
     * Returns the complete state required by the teacher setup screen.
     *
     * @param int $courseid Course id.
     * @param int $vplid Optional selected VPL id.
     * @return array
     */
    public static function get_state(int $courseid, int $vplid = 0): array {
        $activities = vpl_repository::get_activities($courseid);
        $selectedactivity = null;
        $config = null;
        $criteria = [];
        $students = [];
        $manuals = [];
        $aitests = [];

        if ($vplid > 0) {
            $selectedactivity = vpl_repository::get_activity($courseid, $vplid);
            $record = self::get_config_record($courseid, $vplid);
            if ($record) {
                $config = self::format_config($record);
                $criteria = self::get_criteria((int)$record->id);
                $manuals = self::get_manuals((int)$record->id);
                $aitests = self::get_ai_tests((int)$record->id);
            }
            $students = vpl_repository::get_students_with_submissions($courseid, $vplid);
        }

        return [
            'settings' => self::get_public_settings(),
            'activities' => $activities,
            'selectedActivity' => $selectedactivity,
            'config' => $config,
            'criteria' => $criteria,
            'students' => $students,
            'manuals' => $manuals,
            'aiTests' => $aitests,
            'weightTotal' => self::total_weight($criteria),
        ];
    }

    /**
     * Saves the VPL grading configuration and synchronizes criteria/levels.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload from the browser.
     * @param int $teacherid Current teacher id.
     * @return array
     */
    public static function save_configuration(int $courseid, array $payload, int $teacherid): array {
        global $DB;

        $vplid = self::require_int($payload, 'vplid');
        vpl_repository::get_activity($courseid, $vplid);

        $criteria = self::normalise_criteria_payload($payload['criteria'] ?? []);
        if (empty($criteria)) {
            throw new \moodle_exception('nocriteria', 'local_ai_grading');
        }

        $transaction = $DB->start_delegated_transaction();

        $now = time();
        $config = self::get_config_record($courseid, $vplid);
        if (!$config) {
            $config = (object)[
                'courseid' => $courseid,
                'vplid' => $vplid,
                'teacherid' => $teacherid,
                'prompt' => (string)($payload['prompt'] ?? ''),
                'timecreated' => $now,
            ];
            $config->id = $DB->insert_record('local_ai_grading_config', $config);
        } else {
            $config->teacherid = $teacherid;
            $config->prompt = (string)($payload['prompt'] ?? '');
            $DB->update_record('local_ai_grading_config', $config);
        }

        self::sync_criteria((int)$config->id, $criteria, $now);

        $transaction->allow_commit();

        $savedcriteria = self::get_criteria((int)$config->id);

        return [
            'config' => self::format_config(self::get_config_by_id((int)$config->id)),
            'criteria' => $savedcriteria,
            'weightTotal' => self::total_weight($savedcriteria),
            'manuals' => self::get_manuals((int)$config->id),
            'aiTests' => self::get_ai_tests((int)$config->id),
            'message' => self::weight_message($savedcriteria),
        ];
    }

    /**
     * Gets one submission with its code and execution data.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @return array
     */
    public static function get_submission(int $courseid, array $payload): array {
        $vplid = self::require_int($payload, 'vplid');
        $studentid = self::require_int($payload, 'studentid');
        $submissionid = self::require_int($payload, 'submissionid');

        return vpl_repository::get_submission($courseid, $vplid, $studentid, $submissionid);
    }

    /**
     * Saves a manual reference grading.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @return array
     */
    public static function save_manual(int $courseid, array $payload): array {
        global $DB;

        $config = self::require_config_for_payload($courseid, $payload);
        $studentid = self::require_int($payload, 'studentid');
        $submissionid = self::require_int($payload, 'submissionid');
        vpl_repository::validate_submission($courseid, (int)$config->vplid, $studentid, $submissionid);

        $criteria = self::get_criteria((int)$config->id);
        if (empty($criteria)) {
            throw new \moodle_exception('nocriteria', 'local_ai_grading');
        }

        $details = self::normalise_detail_payload($payload['criteria'] ?? [], $criteria);
        $total = self::calculate_total_from_levels($criteria, $details);

        $transaction = $DB->start_delegated_transaction();

        $manual = (object)[
            'configid' => (int)$config->id,
            'studentid' => $studentid,
            'submissionid' => $submissionid,
            'selectiontype' => self::valid_selection_type((string)($payload['selectiontype'] ?? 'specific')),
            'totalgrade' => $total,
            'generalobservations' => (string)($payload['generalobservations'] ?? ''),
            'timecreated' => time(),
        ];
        $manual->id = $DB->insert_record('local_ai_grading_manual', $manual);

        foreach ($details as $detail) {
            $DB->insert_record('local_ai_grading_mancrit', (object)[
                'manualid' => (int)$manual->id,
                'criterionid' => (int)$detail['criterionid'],
                'levelid' => (int)$detail['levelid'],
                'observation' => (string)$detail['observation'],
            ]);
        }

        $transaction->allow_commit();

        return [
            'manual' => self::get_manual((int)$manual->id),
            'manuals' => self::get_manuals((int)$config->id),
            'message' => get_string('manualsaved', 'local_ai_grading'),
        ];
    }

    /**
     * Deletes a manual grading record and its details.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @return array
     */
    public static function delete_manual(int $courseid, array $payload): array {
        global $DB;

        $manualid = self::require_int($payload, 'manualid');
        $manual = $DB->get_record('local_ai_grading_manual', ['id' => $manualid], '*', MUST_EXIST);
        $config = self::get_config_by_id((int)$manual->configid);
        self::assert_config_course($config, $courseid);

        $DB->delete_records('local_ai_grading_mancrit', ['manualid' => $manualid]);
        $DB->delete_records('local_ai_grading_manual', ['id' => $manualid]);

        return [
            'manuals' => self::get_manuals((int)$config->id),
            'message' => get_string('manualdeleted', 'local_ai_grading'),
        ];
    }

    /**
     * Runs and saves an AI test grading.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @param int $teacherid Current teacher id.
     * @return array
     */
    public static function run_ai_test(int $courseid, array $payload, int $teacherid): array {
        global $DB;

        $config = self::require_config_for_payload($courseid, $payload);
        $studentid = self::require_int($payload, 'studentid');
        $submissionid = self::require_int($payload, 'submissionid');
        vpl_repository::validate_submission($courseid, (int)$config->vplid, $studentid, $submissionid);

        $criteria = self::get_criteria((int)$config->id);
        if (empty($criteria)) {
            throw new \moodle_exception('nocriteria', 'local_ai_grading');
        }
        foreach ($criteria as $criterion) {
            if (empty($criterion['levels'])) {
                throw new \moodle_exception('criterionwithoutlevels', 'local_ai_grading');
            }
        }

        $submission = vpl_repository::get_submission($courseid, (int)$config->vplid, $studentid, $submissionid);
        $payloadtosend = self::build_external_payload($courseid, $config, $teacherid, $submission, $criteria);

        $timesent = time();
        $response = ai_client::run($payloadtosend, $criteria);
        $timereceived = time();

        $details = self::normalise_ai_response_details($response['criteria'] ?? [], $criteria);
        $total = isset($response['total_grade']) && is_numeric($response['total_grade'])
            ? (float)$response['total_grade']
            : self::calculate_total_from_levels($criteria, $details);

        $transaction = $DB->start_delegated_transaction();

        $aitest = (object)[
            'configid' => (int)$config->id,
            'studentid' => $studentid,
            'submissionid' => $submissionid,
            'totalgrade' => round($total, 2),
            'generalfeedback' => (string)($response['general_feedback'] ?? ''),
            'timesent' => $timesent,
            'timereceived' => $timereceived,
        ];
        $aitest->id = $DB->insert_record('local_ai_grading_aitest', $aitest);

        foreach ($details as $detail) {
            $DB->insert_record('local_ai_grading_aitcrit', (object)[
                'aitestid' => (int)$aitest->id,
                'criterionid' => (int)$detail['criterionid'],
                'levelid' => (int)$detail['levelid'],
                'aidetail' => (string)$detail['detail'],
            ]);
        }

        $transaction->allow_commit();

        $test = self::get_ai_test((int)$aitest->id);
        $test['mode'] = $response['mode'] ?? get_config('local_ai_grading', 'mode') ?: 'mock';

        return [
            'test' => $test,
            'aiTests' => self::get_ai_tests((int)$config->id),
            'message' => (string)($response['message'] ?? get_string('aitestsaved', 'local_ai_grading')),
        ];
    }

    /**
     * Deletes an AI test and its details.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @return array
     */
    public static function delete_ai_test(int $courseid, array $payload): array {
        global $DB;

        $testid = self::require_int($payload, 'testid');
        $test = $DB->get_record('local_ai_grading_aitest', ['id' => $testid], '*', MUST_EXIST);
        $config = self::get_config_by_id((int)$test->configid);
        self::assert_config_course($config, $courseid);

        $DB->delete_records('local_ai_grading_aitcrit', ['aitestid' => $testid]);
        $DB->delete_records('local_ai_grading_aitest', ['id' => $testid]);

        return [
            'aiTests' => self::get_ai_tests((int)$config->id),
            'message' => get_string('aitestdeleted', 'local_ai_grading'),
        ];
    }

    /**
     * Returns non-sensitive plugin settings.
     *
     * @return array
     */
    private static function get_public_settings(): array {
        $mode = get_config('local_ai_grading', 'mode') ?: 'mock';
        $url = trim((string)get_config('local_ai_grading', 'service_url'));
        $token = trim((string)get_config('local_ai_grading', 'service_token'));

        return [
            'mode' => $mode === 'external' ? 'external' : 'mock',
            'externalConfigured' => $url !== '' && $token !== '',
            'timeout' => (int)(get_config('local_ai_grading', 'timeout') ?: 30),
        ];
    }

    /**
     * Gets current config by course and VPL.
     *
     * @param int $courseid Course id.
     * @param int $vplid VPL instance id.
     * @return \stdClass|null
     */
    private static function get_config_record(int $courseid, int $vplid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('local_ai_grading_config', [
            'courseid' => $courseid,
            'vplid' => $vplid,
        ]);

        return $record ?: null;
    }

    /**
     * Gets config by id.
     *
     * @param int $configid Config id.
     * @return \stdClass
     */
    private static function get_config_by_id(int $configid): \stdClass {
        global $DB;

        return $DB->get_record('local_ai_grading_config', ['id' => $configid], '*', MUST_EXIST);
    }

    /**
     * Validates and gets config referenced by a payload.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @return \stdClass
     */
    private static function require_config_for_payload(int $courseid, array $payload): \stdClass {
        $configid = self::require_int($payload, 'configid');
        $config = self::get_config_by_id($configid);
        self::assert_config_course($config, $courseid);
        return $config;
    }

    /**
     * Ensures the config belongs to the current course.
     *
     * @param \stdClass $config Config.
     * @param int $courseid Course id.
     * @return void
     */
    private static function assert_config_course(\stdClass $config, int $courseid): void {
        if ((int)$config->courseid !== $courseid) {
            throw new \moodle_exception('invalidcourse', 'local_ai_grading');
        }
    }

    /**
     * Formats config.
     *
     * @param \stdClass $config Config record.
     * @return array
     */
    private static function format_config(\stdClass $config): array {
        return [
            'id' => (int)$config->id,
            'courseid' => (int)$config->courseid,
            'vplid' => (int)$config->vplid,
            'teacherid' => (int)$config->teacherid,
            'prompt' => (string)$config->prompt,
            'timecreated' => (int)$config->timecreated,
            'timecreatedText' => userdate((int)$config->timecreated),
        ];
    }

    /**
     * Loads criteria and nested levels.
     *
     * @param int $configid Config id.
     * @return array
     */
    private static function get_criteria(int $configid): array {
        global $DB;

        $records = $DB->get_records('local_ai_grading_criterion', ['configid' => $configid], 'sortorder ASC, id ASC');
        if (empty($records)) {
            return [];
        }

        $criteria = [];
        foreach ($records as $record) {
            $criteria[(int)$record->id] = [
                'id' => (int)$record->id,
                'name' => (string)$record->name,
                'description' => (string)$record->description,
                'weight' => (float)$record->weight,
                'sortorder' => (int)$record->sortorder,
                'levels' => [],
            ];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($criteria), SQL_PARAMS_NAMED);
        $levels = $DB->get_records_select('local_ai_grading_level', "criterionid $insql", $params, 'sortorder ASC, id ASC');
        foreach ($levels as $level) {
            $criterionid = (int)$level->criterionid;
            if (!isset($criteria[$criterionid])) {
                continue;
            }
            $criteria[$criterionid]['levels'][] = [
                'id' => (int)$level->id,
                'criterionid' => $criterionid,
                'name' => (string)$level->name,
                'percentage' => (float)$level->percentage,
                'description' => (string)$level->description,
                'sortorder' => (int)$level->sortorder,
            ];
        }

        return array_values($criteria);
    }

    /**
     * Synchronizes criteria and levels.
     *
     * @param int $configid Config id.
     * @param array $criteria Criteria payload.
     * @param int $now Current timestamp.
     * @return void
     */
    private static function sync_criteria(int $configid, array $criteria, int $now): void {
        global $DB;

        $savedcriterionids = [];

        foreach ($criteria as $index => $criterion) {
            $criterionid = (int)($criterion['id'] ?? 0);
            $record = null;

            if ($criterionid > 0) {
                $record = $DB->get_record('local_ai_grading_criterion', [
                    'id' => $criterionid,
                    'configid' => $configid,
                ]);
            }

            if ($record) {
                $record->name = $criterion['name'];
                $record->description = $criterion['description'];
                $record->weight = $criterion['weight'];
                $record->sortorder = $index + 1;
                $DB->update_record('local_ai_grading_criterion', $record);
            } else {
                $record = (object)[
                    'configid' => $configid,
                    'name' => $criterion['name'],
                    'description' => $criterion['description'],
                    'weight' => $criterion['weight'],
                    'sortorder' => $index + 1,
                    'timecreated' => $now,
                ];
                $record->id = $DB->insert_record('local_ai_grading_criterion', $record);
            }

            $savedcriterionids[] = (int)$record->id;
            self::sync_levels((int)$record->id, $criterion['levels'], $now);
        }

        self::delete_removed_criteria($configid, $savedcriterionids);
    }

    /**
     * Synchronizes levels for one criterion.
     *
     * @param int $criterionid Criterion id.
     * @param array $levels Levels payload.
     * @param int $now Current timestamp.
     * @return void
     */
    private static function sync_levels(int $criterionid, array $levels, int $now): void {
        global $DB;

        $savedlevelids = [];

        foreach ($levels as $index => $level) {
            $levelid = (int)($level['id'] ?? 0);
            $record = null;

            if ($levelid > 0) {
                $record = $DB->get_record('local_ai_grading_level', [
                    'id' => $levelid,
                    'criterionid' => $criterionid,
                ]);
            }

            if ($record) {
                $record->name = $level['name'];
                $record->percentage = $level['percentage'];
                $record->description = $level['description'];
                $record->sortorder = $index + 1;
                $DB->update_record('local_ai_grading_level', $record);
            } else {
                $record = (object)[
                    'criterionid' => $criterionid,
                    'name' => $level['name'],
                    'percentage' => $level['percentage'],
                    'description' => $level['description'],
                    'sortorder' => $index + 1,
                    'timecreated' => $now,
                ];
                $record->id = $DB->insert_record('local_ai_grading_level', $record);
            }

            $savedlevelids[] = (int)$record->id;
        }

        self::delete_removed_levels($criterionid, $savedlevelids);
    }

    /**
     * Deletes criteria omitted from the latest save.
     *
     * @param int $configid Config id.
     * @param array $keepids Criterion ids to keep.
     * @return void
     */
    private static function delete_removed_criteria(int $configid, array $keepids): void {
        global $DB;

        $existing = $DB->get_records('local_ai_grading_criterion', ['configid' => $configid], '', 'id');
        foreach ($existing as $record) {
            $criterionid = (int)$record->id;
            if (in_array($criterionid, $keepids, true)) {
                continue;
            }

            self::delete_references_for_criterion($criterionid);
            $DB->delete_records('local_ai_grading_level', ['criterionid' => $criterionid]);
            $DB->delete_records('local_ai_grading_criterion', ['id' => $criterionid]);
        }
    }

    /**
     * Deletes levels omitted from the latest save.
     *
     * @param int $criterionid Criterion id.
     * @param array $keepids Level ids to keep.
     * @return void
     */
    private static function delete_removed_levels(int $criterionid, array $keepids): void {
        global $DB;

        $existing = $DB->get_records('local_ai_grading_level', ['criterionid' => $criterionid], '', 'id');
        foreach ($existing as $record) {
            $levelid = (int)$record->id;
            if (in_array($levelid, $keepids, true)) {
                continue;
            }

            self::delete_references_for_level($levelid);
            $DB->delete_records('local_ai_grading_level', ['id' => $levelid]);
        }
    }

    /**
     * Deletes detail references for one criterion.
     *
     * @param int $criterionid Criterion id.
     * @return void
     */
    private static function delete_references_for_criterion(int $criterionid): void {
        global $DB;

        $DB->delete_records('local_ai_grading_mancrit', ['criterionid' => $criterionid]);
        $DB->delete_records('local_ai_grading_aitcrit', ['criterionid' => $criterionid]);
        $DB->delete_records('local_ai_grading_rescrit', ['criterionid' => $criterionid]);
    }

    /**
     * Deletes detail references for one level.
     *
     * @param int $levelid Level id.
     * @return void
     */
    private static function delete_references_for_level(int $levelid): void {
        global $DB;

        $DB->delete_records('local_ai_grading_mancrit', ['levelid' => $levelid]);
        $DB->delete_records('local_ai_grading_aitcrit', ['levelid' => $levelid]);
        $DB->delete_records('local_ai_grading_rescrit', ['levelid' => $levelid]);
    }

    /**
     * Normalizes criterion payload values.
     *
     * @param mixed $criteria Raw criteria.
     * @return array
     */
    private static function normalise_criteria_payload($criteria): array {
        if (!is_array($criteria)) {
            throw new \moodle_exception('invalidcriteria', 'local_ai_grading');
        }

        $normalised = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }

            $name = trim((string)($criterion['name'] ?? ''));
            if ($name === '') {
                throw new \moodle_exception('criterionnamerequired', 'local_ai_grading');
            }

            $weight = self::number_in_range($criterion['weight'] ?? 0, 0, 100);
            $levels = self::normalise_levels_payload($criterion['levels'] ?? []);

            $normalised[] = [
                'id' => self::optional_positive_int($criterion['id'] ?? 0),
                'name' => clean_param($name, PARAM_TEXT),
                'description' => clean_param((string)($criterion['description'] ?? ''), PARAM_TEXT),
                'weight' => $weight,
                'levels' => $levels,
            ];
        }

        return $normalised;
    }

    /**
     * Normalizes level payload values.
     *
     * @param mixed $levels Raw levels.
     * @return array
     */
    private static function normalise_levels_payload($levels): array {
        if (!is_array($levels) || empty($levels)) {
            throw new \moodle_exception('levelsrequired', 'local_ai_grading');
        }

        $normalised = [];
        foreach ($levels as $level) {
            if (!is_array($level)) {
                continue;
            }

            $name = trim((string)($level['name'] ?? ''));
            if ($name === '') {
                throw new \moodle_exception('levelnamerequired', 'local_ai_grading');
            }

            $normalised[] = [
                'id' => self::optional_positive_int($level['id'] ?? 0),
                'name' => clean_param($name, PARAM_TEXT),
                'percentage' => self::number_in_range($level['percentage'] ?? 0, 0, 100),
                'description' => clean_param((string)($level['description'] ?? ''), PARAM_TEXT),
            ];
        }

        if (empty($normalised)) {
            throw new \moodle_exception('levelsrequired', 'local_ai_grading');
        }

        return $normalised;
    }

    /**
     * Normalizes manual criterion detail payload.
     *
     * @param mixed $details Raw details.
     * @param array $criteria Current criteria.
     * @return array
     */
    private static function normalise_detail_payload($details, array $criteria): array {
        if (!is_array($details)) {
            throw new \moodle_exception('invalidcriteria', 'local_ai_grading');
        }

        $criteriamap = self::criteria_level_map($criteria);
        $normalised = [];

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $criterionid = (int)($detail['criterionid'] ?? $detail['criterion_id'] ?? 0);
            $levelid = (int)($detail['levelid'] ?? $detail['level_id'] ?? 0);
            if (!isset($criteriamap[$criterionid]) || !isset($criteriamap[$criterionid]['levels'][$levelid])) {
                throw new \moodle_exception('invalidlevelselection', 'local_ai_grading');
            }

            $normalised[$criterionid] = [
                'criterionid' => $criterionid,
                'levelid' => $levelid,
                'observation' => clean_param((string)($detail['observation'] ?? ''), PARAM_TEXT),
            ];
        }

        foreach ($criteriamap as $criterionid => $criterion) {
            if (!isset($normalised[$criterionid])) {
                throw new \moodle_exception('manualincomplete', 'local_ai_grading');
            }
        }

        return array_values($normalised);
    }

    /**
     * Normalizes AI criterion details.
     *
     * @param mixed $details AI details.
     * @param array $criteria Current criteria.
     * @return array
     */
    private static function normalise_ai_response_details($details, array $criteria): array {
        if (!is_array($details)) {
            throw new \moodle_exception('externalinvalidresponse', 'local_ai_grading');
        }

        $criteriamap = self::criteria_level_map($criteria);
        $normalised = [];

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $criterionid = (int)($detail['criterion_id'] ?? $detail['criterionid'] ?? 0);
            $levelid = (int)($detail['level_id'] ?? $detail['levelid'] ?? 0);
            if (!isset($criteriamap[$criterionid]) || !isset($criteriamap[$criterionid]['levels'][$levelid])) {
                throw new \moodle_exception('externalinvalidlevel', 'local_ai_grading');
            }

            $normalised[$criterionid] = [
                'criterionid' => $criterionid,
                'levelid' => $levelid,
                'detail' => clean_param((string)($detail['detail'] ?? ''), PARAM_TEXT),
            ];
        }

        foreach ($criteriamap as $criterionid => $criterion) {
            if (!isset($normalised[$criterionid])) {
                throw new \moodle_exception('externalmissingcriterion', 'local_ai_grading');
            }
        }

        return array_values($normalised);
    }

    /**
     * Creates a criterion to level map.
     *
     * @param array $criteria Criteria.
     * @return array
     */
    private static function criteria_level_map(array $criteria): array {
        $map = [];
        foreach ($criteria as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] as $level) {
                $levels[(int)$level['id']] = $level;
            }
            $map[(int)$criterion['id']] = [
                'criterion' => $criterion,
                'levels' => $levels,
            ];
        }
        return $map;
    }

    /**
     * Calculates total from level selections.
     *
     * @param array $criteria Current criteria.
     * @param array $details Details.
     * @return float
     */
    private static function calculate_total_from_levels(array $criteria, array $details): float {
        $map = self::criteria_level_map($criteria);
        $total = 0.0;

        foreach ($details as $detail) {
            $criterion = $map[(int)$detail['criterionid']]['criterion'];
            $level = $map[(int)$detail['criterionid']]['levels'][(int)$detail['levelid']];
            $total += ((float)$criterion['weight'] * (float)$level['percentage']) / 100;
        }

        return round($total, 2);
    }

    /**
     * Builds the webhook payload.
     *
     * @param int $courseid Course id.
     * @param \stdClass $config Config.
     * @param int $teacherid Teacher id.
     * @param array $submission Submission data.
     * @param array $criteria Criteria.
     * @return array
     */
    private static function build_external_payload(
        int $courseid,
        \stdClass $config,
        int $teacherid,
        array $submission,
        array $criteria
    ): array {
        return [
            'course_id' => (string)$courseid,
            'vpl_activity_id' => (string)$config->vplid,
            'teacher_id' => (string)$teacherid,
            'student_id' => (string)$submission['studentid'],
            'submission_id' => (string)$submission['id'],
            'prompt' => (string)$config->prompt,
            'criteria' => array_map(static function(array $criterion): array {
                return [
                    'id' => (string)$criterion['id'],
                    'name' => $criterion['name'],
                    'description' => $criterion['description'],
                    'weight' => (string)$criterion['weight'],
                    'levels' => array_map(static function(array $level): array {
                        return [
                            'id' => (string)$level['id'],
                            'name' => $level['name'],
                            'percentage' => (string)$level['percentage'],
                            'description' => $level['description'],
                        ];
                    }, $criterion['levels']),
                ];
            }, $criteria),
            'submission' => [
                'attempt_no' => (string)$submission['attemptNo'],
                'source_code' => $submission['source_code'],
                'execution_output' => $submission['execution_output'] ?? '',
            ],
        ];
    }

    /**
     * Returns one manual record.
     *
     * @param int $manualid Manual id.
     * @return array
     */
    private static function get_manual(int $manualid): array {
        global $DB;

        $sql = "SELECT m.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username
                  FROM {local_ai_grading_manual} m
                  JOIN {user} u ON u.id = m.studentid
                 WHERE m.id = :manualid";
        $record = $DB->get_record_sql($sql, ['manualid' => $manualid], MUST_EXIST);
        return self::format_manual($record);
    }

    /**
     * Returns manual records for a config.
     *
     * @param int $configid Config id.
     * @return array
     */
    private static function get_manuals(int $configid): array {
        global $DB;

        $sql = "SELECT m.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username
                  FROM {local_ai_grading_manual} m
                  JOIN {user} u ON u.id = m.studentid
                 WHERE m.configid = :configid
              ORDER BY m.timecreated DESC, m.id DESC";

        $records = $DB->get_records_sql($sql, ['configid' => $configid]);
        return array_map(static function(\stdClass $record): array {
            return self::format_manual($record);
        }, array_values($records));
    }

    /**
     * Formats manual record.
     *
     * @param \stdClass $record Manual row.
     * @return array
     */
    private static function format_manual(\stdClass $record): array {
        return [
            'id' => (int)$record->id,
            'configid' => (int)$record->configid,
            'studentid' => (int)$record->studentid,
            'studentName' => fullname($record),
            'studentUsername' => (string)$record->username,
            'submissionid' => (int)$record->submissionid,
            'selectiontype' => (string)$record->selectiontype,
            'totalgrade' => round((float)$record->totalgrade, 2),
            'generalobservations' => (string)$record->generalobservations,
            'timecreated' => (int)$record->timecreated,
            'timecreatedText' => userdate((int)$record->timecreated),
        ];
    }

    /**
     * Returns one AI test with details.
     *
     * @param int $testid Test id.
     * @return array
     */
    private static function get_ai_test(int $testid): array {
        global $DB;

        $sql = "SELECT t.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username
                  FROM {local_ai_grading_aitest} t
                  JOIN {user} u ON u.id = t.studentid
                 WHERE t.id = :testid";
        $record = $DB->get_record_sql($sql, ['testid' => $testid], MUST_EXIST);
        $formatted = self::format_ai_test($record);
        $formatted['details'] = self::get_ai_test_details($testid);
        return $formatted;
    }

    /**
     * Returns AI test records for a config.
     *
     * @param int $configid Config id.
     * @return array
     */
    private static function get_ai_tests(int $configid): array {
        global $DB;

        $sql = "SELECT t.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username
                  FROM {local_ai_grading_aitest} t
                  JOIN {user} u ON u.id = t.studentid
                 WHERE t.configid = :configid
              ORDER BY t.timereceived DESC, t.id DESC";

        $records = $DB->get_records_sql($sql, ['configid' => $configid]);
        return array_map(static function(\stdClass $record): array {
            return self::format_ai_test($record);
        }, array_values($records));
    }

    /**
     * Returns AI test criterion details.
     *
     * @param int $testid Test id.
     * @return array
     */
    private static function get_ai_test_details(int $testid): array {
        global $DB;

        $sql = "SELECT d.id, d.aitestid, d.criterionid, d.levelid, d.aidetail,
                       c.name AS criterionname, c.weight, l.name AS levelname, l.percentage
                  FROM {local_ai_grading_aitcrit} d
                  JOIN {local_ai_grading_criterion} c ON c.id = d.criterionid
                  JOIN {local_ai_grading_level} l ON l.id = d.levelid
                 WHERE d.aitestid = :testid
              ORDER BY c.sortorder ASC, c.id ASC";

        $records = $DB->get_records_sql($sql, ['testid' => $testid]);
        $details = [];
        foreach ($records as $record) {
            $details[] = [
                'id' => (int)$record->id,
                'criterionid' => (int)$record->criterionid,
                'criterionName' => (string)$record->criterionname,
                'levelid' => (int)$record->levelid,
                'levelName' => (string)$record->levelname,
                'percentage' => (float)$record->percentage,
                'score' => round(((float)$record->weight * (float)$record->percentage) / 100, 2),
                'max' => (float)$record->weight,
                'detail' => (string)$record->aidetail,
            ];
        }
        return $details;
    }

    /**
     * Formats AI test record.
     *
     * @param \stdClass $record AI test row.
     * @return array
     */
    private static function format_ai_test(\stdClass $record): array {
        return [
            'id' => (int)$record->id,
            'configid' => (int)$record->configid,
            'studentid' => (int)$record->studentid,
            'studentName' => fullname($record),
            'studentUsername' => (string)$record->username,
            'submissionid' => (int)$record->submissionid,
            'totalgrade' => round((float)$record->totalgrade, 2),
            'generalfeedback' => (string)$record->generalfeedback,
            'timesent' => (int)$record->timesent,
            'timereceived' => (int)$record->timereceived,
            'timereceivedText' => userdate((int)$record->timereceived),
        ];
    }

    /**
     * Validates selection type.
     *
     * @param string $type Raw type.
     * @return string
     */
    private static function valid_selection_type(string $type): string {
        return $type === 'random' ? 'random' : 'specific';
    }

    /**
     * Gets a required integer payload value.
     *
     * @param array $payload Payload.
     * @param string $name Field name.
     * @return int
     */
    private static function require_int(array $payload, string $name): int {
        $value = isset($payload[$name]) ? (int)$payload[$name] : 0;
        if ($value <= 0) {
            throw new \moodle_exception('missingparam', 'error', '', $name);
        }
        return $value;
    }

    /**
     * Keeps a positive integer only when the value is already numeric.
     *
     * @param mixed $value Value.
     * @return int
     */
    private static function optional_positive_int($value): int {
        if (!is_numeric($value)) {
            return 0;
        }
        return max(0, (int)$value);
    }

    /**
     * Normalizes a number to a range.
     *
     * @param mixed $value Raw number.
     * @param float $min Minimum.
     * @param float $max Maximum.
     * @return float
     */
    private static function number_in_range($value, float $min, float $max): float {
        if (!is_numeric($value)) {
            throw new \moodle_exception('invalidnumber', 'local_ai_grading');
        }
        return min($max, max($min, (float)$value));
    }

    /**
     * Sums weights.
     *
     * @param array $criteria Criteria.
     * @return float
     */
    private static function total_weight(array $criteria): float {
        $total = 0.0;
        foreach ($criteria as $criterion) {
            $total += (float)$criterion['weight'];
        }
        return round($total, 2);
    }

    /**
     * Creates a weight validation message.
     *
     * @param array $criteria Criteria.
     * @return string
     */
    private static function weight_message(array $criteria): string {
        $total = self::total_weight($criteria);
        if (abs($total - 100.0) < 0.001) {
            return get_string('configsaved', 'local_ai_grading');
        }

        return get_string('configsavedweightwarning', 'local_ai_grading', $total);
    }
}
