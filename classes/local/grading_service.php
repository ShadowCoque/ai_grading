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
        // Huella anterior de la rúbrica (vacía para una configuración nueva).
        $oldfingerprint = ($config && isset($config->rubricfingerprint)) ? (string)$config->rubricfingerprint : '';
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

        // Si la rúbrica (criterios, niveles o calificaciones manuales) cambió respecto
        // a la última configuración, las evaluaciones IA previas dejan de ser válidas:
        // se reinician a "pendiente" y se despublican, obligando a re-evaluar.
        $newfingerprint = self::rubric_fingerprint((int)$config->id);
        $resultsreset = 0;
        $rubricchanged = false;
        if ($oldfingerprint !== '' && $oldfingerprint !== $newfingerprint) {
            $rubricchanged = true;
            $resultsreset = self::reset_results_for_config((int)$config->id);
        }
        $DB->set_field('local_ai_grading_config', 'rubricfingerprint', $newfingerprint, ['id' => (int)$config->id]);

        $transaction->allow_commit();

        $savedcriteria = self::get_criteria((int)$config->id);

        return [
            'config' => self::format_config(self::get_config_by_id((int)$config->id)),
            'criteria' => $savedcriteria,
            'weightTotal' => self::total_weight($savedcriteria),
            'manuals' => self::get_manuals((int)$config->id),
            'aiTests' => self::get_ai_tests((int)$config->id),
            'rubricChanged' => $rubricchanged,
            'resultsReset' => $resultsreset,
            'message' => self::weight_message($savedcriteria),
        ];
    }

    /**
     * Computes a deterministic fingerprint of the elements that, if changed,
     * invalidate previous AI evaluations: criteria, levels and manual references.
     *
     * @param int $configid Config id.
     * @return string
     */
    private static function rubric_fingerprint(int $configid): string {
        global $DB;

        $parts = ['c' => [], 'm' => []];
        foreach (self::get_criteria($configid) as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] as $level) {
                $levels[] = [(int)$level['id'], (string)$level['name'], (float)$level['percentage']];
            }
            $parts['c'][] = [(int)$criterion['id'], (string)$criterion['name'], (float)$criterion['weight'], $levels];
        }

        $manuals = $DB->get_records('local_ai_grading_manual', ['configid' => $configid], 'id ASC');
        foreach ($manuals as $manual) {
            $rows = $DB->get_records(
                'local_ai_grading_mancrit',
                ['manualid' => $manual->id],
                'criterionid ASC',
                'id, criterionid, levelid'
            );
            $mc = [];
            foreach ($rows as $row) {
                $mc[] = [(int)$row->criterionid, (int)$row->levelid];
            }
            $parts['m'][] = [(int)$manual->studentid, (int)$manual->submissionid, $mc];
        }

        return sha1(json_encode($parts));
    }

    /**
     * Resets every non-pending result of a config back to "pending" and clears any
     * AI evaluation, teacher review and publication. Used when the rubric changes.
     *
     * @param int $configid Config id.
     * @return int Number of results that had an evaluation and were reset.
     */
    private static function reset_results_for_config(int $configid): int {
        global $DB;

        $results = $DB->get_records('local_ai_grading_result', ['configid' => $configid]);
        $reset = 0;
        foreach ($results as $result) {
            $hadevaluation = $result->aistatus !== 'pending'
                || $result->aitotalgrade !== null
                || !empty($result->timepublished);
            if (!$hadevaluation) {
                continue;
            }

            $DB->delete_records('local_ai_grading_rescrit', ['resultid' => $result->id]);
            $result->aistatus = 'pending';
            $result->aitotalgrade = null;
            $result->finaltotalgrade = null;
            $result->finalfeedback = null;
            $result->studentfeedback = null;
            $result->errordetail = null;
            $result->reviewedby = null;
            $result->publishedby = null;
            $result->timeevaluated = null;
            $result->timereviewed = null;
            $result->timepublished = null;
            $result->timestudentviewed = null;
            $DB->update_record('local_ai_grading_result', $result);
            $reset++;
        }
        return $reset;
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
        $manualid = self::optional_positive_int($payload['manualid'] ?? 0);
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

        if ($manualid > 0) {
            $manual = $DB->get_record('local_ai_grading_manual', [
                'id' => $manualid,
                'configid' => (int)$config->id,
            ], '*', MUST_EXIST);
            $manual->studentid = $studentid;
            $manual->submissionid = $submissionid;
            $manual->selectiontype = self::valid_selection_type((string)($payload['selectiontype'] ?? 'specific'));
            $manual->totalgrade = $total;
            $manual->generalobservations = (string)($payload['generalobservations'] ?? '');
            $manual->timecreated = time();
            $DB->update_record('local_ai_grading_manual', $manual);
            $DB->delete_records('local_ai_grading_mancrit', ['manualid' => $manualid]);
        } else {
            if ($DB->count_records('local_ai_grading_manual', ['configid' => (int)$config->id]) >= 3) {
                throw new \moodle_exception('manualmaxreferences', 'local_ai_grading');
            }

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
        }

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
            'message' => get_string($manualid > 0 ? 'manualupdated' : 'manualsaved', 'local_ai_grading'),
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
        $total = self::calculate_total_from_levels($criteria, $details);

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
     * Returns the second interface state with persistent student result rows.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @return array
     */
    public static function get_results_state(int $courseid, array $payload): array {
        $config = self::require_config_for_payload($courseid, $payload);
        $activity = vpl_repository::get_activity($courseid, (int)$config->vplid);
        $criteria = self::get_criteria((int)$config->id);
        $students = vpl_repository::get_students_with_submissions($courseid, (int)$config->vplid);

        self::ensure_result_records($config, $students);

        return [
            'settings' => self::get_public_settings(),
            'activity' => $activity,
            'config' => self::format_config($config),
            'criteria' => $criteria,
            'results' => self::get_results((int)$config->id),
            'summary' => self::result_summary((int)$config->id),
        ];
    }

    /**
     * Runs and saves a real AI result for one student submission.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @param int $teacherid Current teacher id.
     * @return array
     */
    public static function run_result_ai(int $courseid, array $payload, int $teacherid): array {
        global $DB;

        $resultid = self::require_int($payload, 'resultid');
        $result = $DB->get_record('local_ai_grading_result', ['id' => $resultid], '*', MUST_EXIST);
        $config = self::get_config_by_id((int)$result->configid);
        self::assert_config_course($config, $courseid);

        $criteria = self::get_criteria((int)$config->id);
        if (empty($criteria)) {
            throw new \moodle_exception('nocriteria', 'local_ai_grading');
        }
        foreach ($criteria as $criterion) {
            if (empty($criterion['levels'])) {
                throw new \moodle_exception('criterionwithoutlevels', 'local_ai_grading');
            }
        }

        $now = time();
        $result->aistatus = 'processing';
        $result->errordetail = null;
        $DB->update_record('local_ai_grading_result', $result);

        try {
            $submission = vpl_repository::get_submission(
                $courseid,
                (int)$config->vplid,
                (int)$result->studentid,
                (int)$result->submissionid
            );
            $payloadtosend = self::build_external_payload($courseid, $config, $teacherid, $submission, $criteria);
            $response = ai_client::run($payloadtosend, $criteria);
            $details = self::normalise_ai_response_details($response['criteria'] ?? [], $criteria);
            $total = self::calculate_total_from_levels($criteria, $details);

            $transaction = $DB->start_delegated_transaction();

            $result = $DB->get_record('local_ai_grading_result', ['id' => $resultid], '*', MUST_EXIST);
            $result->attemptnumber = (int)$submission['attemptNo'];
            $result->timesubmitted = (int)$submission['datesubmitted'];
            $result->aistatus = 'evaluated';
            $result->aitotalgrade = round($total, 2);
            // La nota final parte de la propuesta IA; el docente la ajusta por criterio.
            $result->finaltotalgrade = round($total, 2);
            // Comentario general REAL de la IA (general_feedback del contrato n8n). Es
            // independiente de los detalles por criterio; antes se descartaba.
            $result->aifeedback = (string)($response['general_feedback'] ?? '');
            $result->errordetail = null;
            $result->timeevaluated = $now;
            // Una evaluación IA nueva reemplaza cualquier revisión/publicación previa:
            // queda lista para que el docente la revise y vuelva a publicar.
            $result->finalfeedback = null;
            $result->studentfeedback = null;
            $result->reviewedby = null;
            $result->publishedby = null;
            $result->timereviewed = null;
            $result->timepublished = null;
            $result->timestudentviewed = null;
            $DB->update_record('local_ai_grading_result', $result);

            self::replace_result_details((int)$result->id, $criteria, $details);

            $transaction->allow_commit();

            return [
                'result' => self::get_result((int)$result->id),
                'results' => self::get_results((int)$config->id),
                'summary' => self::result_summary((int)$config->id),
                'message' => get_string('externalresultnotice', 'local_ai_grading'),
            ];
        } catch (\moodle_exception $exception) {
            $result = $DB->get_record('local_ai_grading_result', ['id' => $resultid], '*', MUST_EXIST);
            $result->aistatus = 'error';
            $result->errordetail = clean_param($exception->getMessage(), PARAM_TEXT);
            $DB->update_record('local_ai_grading_result', $result);

            return [
                'result' => self::get_result((int)$result->id),
                'results' => self::get_results((int)$config->id),
                'summary' => self::result_summary((int)$config->id),
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Saves teacher review fields for one result.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @param int $teacherid Current teacher id.
     * @return array
     */
    public static function save_result_review(int $courseid, array $payload, int $teacherid): array {
        global $DB;

        $resultid = self::require_int($payload, 'resultid');
        $result = $DB->get_record('local_ai_grading_result', ['id' => $resultid], '*', MUST_EXIST);
        $config = self::get_config_by_id((int)$result->configid);
        self::assert_config_course($config, $courseid);

        $criteria = self::get_criteria((int)$config->id);
        $map = self::criteria_level_map($criteria);

        // 1) Validar y calcular ANTES de abrir la transacción. La nota final NO se edita
        // directamente: siempre se deriva de los niveles elegidos por criterio, como suma
        // de (peso × porcentaje del nivel ÷ 100).
        $reviewcriteria = is_array($payload['criteria'] ?? null) ? $payload['criteria'] : [];
        $updates = [];
        $total = null;
        if (!empty($reviewcriteria)) {
            $total = 0.0;
            foreach ($reviewcriteria as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $criterionid = (int)($item['criterionid'] ?? 0);
                $levelid = (int)($item['levelid'] ?? 0);
                if (!isset($map[$criterionid]['levels'][$levelid])) {
                    throw new \moodle_exception('externalinvalidlevel', 'local_ai_grading');
                }
                $criterion = $map[$criterionid]['criterion'];
                $level = $map[$criterionid]['levels'][$levelid];
                $score = round(((float)$criterion['weight'] * (float)$level['percentage']) / 100, 2);
                $total += $score;
                $updates[] = [
                    'criterionid' => $criterionid,
                    'levelid' => $levelid,
                    'score' => $score,
                    'finaldetail' => clean_param((string)($item['finaldetail'] ?? ''), PARAM_TEXT),
                ];
            }
        }

        if ($total === null) {
            // Sin detalle por criterio: conserva la nota final derivada de los niveles ya guardados.
            $total = (float)$DB->get_field_sql(
                'SELECT COALESCE(SUM(finalgrade), 0) FROM {local_ai_grading_rescrit} WHERE resultid = :rid',
                ['rid' => (int)$result->id]
            );
        }

        // 2) Persistir dentro de la transacción.
        $transaction = $DB->start_delegated_transaction();

        foreach ($updates as $update) {
            $row = $DB->get_record('local_ai_grading_rescrit', [
                'resultid' => (int)$result->id,
                'criterionid' => (int)$update['criterionid'],
            ]);
            if ($row) {
                $row->finallevelid = (int)$update['levelid'];
                $row->finalgrade = (float)$update['score'];
                $row->finaldetail = (string)$update['finaldetail'];
                $DB->update_record('local_ai_grading_rescrit', $row);
            }
        }

        $result->finaltotalgrade = round((float)$total, 2);
        $result->finalfeedback = clean_param((string)($payload['finalfeedback'] ?? ''), PARAM_TEXT);
        $result->studentfeedback = clean_param((string)($payload['studentfeedback'] ?? ''), PARAM_TEXT);
        $result->reviewedby = $teacherid;
        $result->timereviewed = time();
        $DB->update_record('local_ai_grading_result', $result);

        $transaction->allow_commit();

        return [
            'result' => self::get_result((int)$result->id),
            'results' => self::get_results((int)$config->id),
            'summary' => self::result_summary((int)$config->id),
            'message' => get_string('resultsaved', 'local_ai_grading'),
        ];
    }

    /**
     * Marks one result as published.
     *
     * @param int $courseid Course id.
     * @param array $payload Payload.
     * @param int $teacherid Current teacher id.
     * @return array
     */
    public static function publish_result(int $courseid, array $payload, int $teacherid): array {
        global $DB;

        $resultid = self::require_int($payload, 'resultid');
        $result = $DB->get_record('local_ai_grading_result', ['id' => $resultid], '*', MUST_EXIST);
        $config = self::get_config_by_id((int)$result->configid);
        self::assert_config_course($config, $courseid);

        if ($result->aistatus !== 'evaluated' || $result->aitotalgrade === null) {
            throw new \moodle_exception('resultnotready', 'local_ai_grading');
        }

        if ($result->finaltotalgrade === null) {
            $result->finaltotalgrade = (float)$result->aitotalgrade;
        }
        // El comentario general ya NO se "hornea" concatenando los detalles por criterio
        // (eso duplicaba el desglose en la vista del estudiante). Si el docente no escribió
        // un comentario general propio (finalfeedback), la vista usa el general real de la
        // IA (aifeedback). Aquí solo se registra la publicación.

        // Publishing on its own does NOT imply the teacher reviewed the AI output.
        // The "reviewed" state is set exclusively by save_result_review(): if the
        // teacher never opened and saved the result, it stays AI-only and the
        // student view reflects that. Here we only record the publication.
        // Each publish resets the student read flag so updated feedback shows as
        // unread again in the floating assistant until the student opens it.
        $now = time();
        $result->publishedby = $teacherid;
        $result->timepublished = $now;
        $result->timestudentviewed = null;
        $DB->update_record('local_ai_grading_result', $result);

        return [
            'result' => self::get_result((int)$result->id),
            'results' => self::get_results((int)$config->id),
            'summary' => self::result_summary((int)$config->id),
            'message' => get_string('resultpublished', 'local_ai_grading'),
        ];
    }

    /**
     * Returns the list of published AI feedback for one student in a course.
     *
     * Read-only and scoped to the given user: only results that the teacher has
     * already published (timepublished set) are returned. One entry per VPL
     * activity (the most recently published one).
     *
     * @param int $courseid Course id.
     * @param int $userid Student id (the current user).
     * @param int $vplid Optional VPL instance id to restrict to one activity.
     * @return array
     */
    public static function get_student_feedback_list(int $courseid, int $userid, int $vplid = 0): array {
        global $DB;

        $params = [
            'courseid' => $courseid,
            'userid' => $userid,
            'modname' => 'vpl',
        ];
        $vplwhere = '';
        if ($vplid > 0) {
            $vplwhere = ' AND cfg.vplid = :vplid';
            $params['vplid'] = $vplid;
        }

        $sql = "SELECT r.id, r.configid, r.submissionid, r.finaltotalgrade, r.aitotalgrade,
                       r.timepublished, r.timereviewed, r.timestudentviewed,
                       cfg.vplid, v.name AS vplname, cm.id AS cmid
                  FROM {local_ai_grading_result} r
                  JOIN {local_ai_grading_config} cfg ON cfg.id = r.configid
                  JOIN {vpl} v ON v.id = cfg.vplid
                  JOIN {course_modules} cm ON cm.instance = v.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cfg.courseid = :courseid
                   AND r.studentid = :userid
                   AND r.timepublished IS NOT NULL
                   AND cm.deletioninprogress = 0
                   $vplwhere
              ORDER BY r.timepublished DESC, r.id DESC";

        $records = $DB->get_records_sql($sql, $params);
        $context = \context_course::instance($courseid);
        $list = [];
        $seen = [];
        foreach ($records as $record) {
            if (isset($seen[(int)$record->vplid])) {
                continue;
            }
            $seen[(int)$record->vplid] = true;
            $grade = $record->finaltotalgrade === null ? null : round((float)$record->finaltotalgrade, 2);
            $reviewedbyteacher = !empty($record->timereviewed);
            $modifiedbyteacher = self::is_result_modified((int)$record->id);
            $read = !empty($record->timestudentviewed);
            $list[] = [
                'resultid' => (int)$record->id,
                'vplid' => (int)$record->vplid,
                'cmid' => (int)$record->cmid,
                'activityName' => format_string($record->vplname, true, ['context' => $context]),
                'grade' => $grade === null ? null : self::format_number($grade),
                'gradeColorClass' => self::grade_color_class($grade, 100),
                'timepublishedText' => userdate((int)$record->timepublished),
                'reviewedByTeacher' => $reviewedbyteacher,
                'modifiedByTeacher' => $modifiedbyteacher,
                'originLabel' => get_string(
                    $reviewedbyteacher ? 'assistant:originteacher' : 'assistant:originai',
                    'local_ai_grading'
                ),
                'read' => $read,
                'unread' => !$read,
                // El estudiante revisa su retroalimentación completa desde la página de
                // edición de su actividad VPL (donde el asistente la muestra en detalle).
                'url' => (new \moodle_url('/mod/vpl/forms/edit.php',
                    ['id' => (int)$record->cmid, 'userid' => $userid]))->out(false),
            ];
        }
        return $list;
    }

    /**
     * Returns the full published AI feedback for one student and VPL activity.
     *
     * @param int $courseid Course id.
     * @param int $userid Student id (the current user).
     * @param int $vplid VPL instance id.
     * @return array|null Feedback payload, or null when nothing is published yet.
     */
    public static function get_student_feedback(int $courseid, int $userid, int $vplid): ?array {
        global $DB;

        $sql = "SELECT r.*
                  FROM {local_ai_grading_result} r
                  JOIN {local_ai_grading_config} cfg ON cfg.id = r.configid
                 WHERE cfg.courseid = :courseid
                   AND cfg.vplid = :vplid
                   AND r.studentid = :userid
                   AND r.timepublished IS NOT NULL
              ORDER BY r.timepublished DESC, r.id DESC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'vplid' => $vplid,
            'userid' => $userid,
        ], 0, 1);
        $record = $records ? reset($records) : null;
        if (!$record) {
            return null;
        }

        $activity = vpl_repository::get_activity($courseid, $vplid);
        $grade = $record->finaltotalgrade === null ? null : round((float)$record->finaltotalgrade, 2);
        $reviewedbyteacher = !empty($record->timereviewed);
        $details = self::get_result_details((int)$record->id);
        $modifiedbyteacher = self::details_modified($details, $record);

        $criteria = [];
        foreach ($details as $detail) {
            $shownscore = $detail['finalscore'] !== null ? $detail['finalscore'] : $detail['score'];
            $showndetail = trim((string)$detail['finaldetail']) !== '' ? $detail['finaldetail'] : $detail['detail'];
            $shownlevel = trim((string)$detail['finalLevelName']) !== '' ? $detail['finalLevelName'] : $detail['levelName'];
            $criteria[] = [
                'name' => $detail['criterionName'],
                'levelName' => $shownlevel,
                'hasLevel' => trim((string)$shownlevel) !== '',
                'score' => $shownscore === null ? null : self::format_number($shownscore),
                'max' => self::format_number($detail['max']),
                'colorClass' => self::grade_color_class($shownscore, (float)$detail['max']),
                'detail' => $showndetail,
                'hasDetail' => trim((string)$showndetail) !== '',
                // Distingue IA vs profesor: marca el criterio que el docente ajustó
                // (nivel o comentario) respecto a la propuesta original de la IA.
                'teacherAdjusted' => $reviewedbyteacher && self::criterion_adjusted($detail),
            ];
        }

        // Comentario general que ve el estudiante: el general REAL de la IA
        // (general_feedback, columna aifeedback). El docente puede escribir uno propio
        // (finalfeedback) que tiene prioridad. PERO los resultados publicados antes de
        // separar el general del desglose tienen en finalfeedback la antigua concatenación
        // horneada de los detalles por criterio ("Criterio: detalle…"); esa forma se detecta
        // y se descarta para no duplicar el desglose que ya se muestra arriba. En resultados
        // antiguos sin aifeedback, el comentario general simplemente queda vacío y no se muestra.
        $teachergeneral = trim((string)$record->finalfeedback);
        if ($teachergeneral !== '' && $teachergeneral === self::baked_ai_feedback($details)) {
            $teachergeneral = '';
        }
        $generalfeedback = $teachergeneral !== '' ? $teachergeneral : (string)$record->aifeedback;

        $sourcecode = '';
        $executionoutput = '';
        try {
            $submission = vpl_repository::get_submission($courseid, $vplid, $userid, (int)$record->submissionid);
            $sourcecode = (string)($submission['source_code'] ?? '');
            $executionoutput = (string)($submission['execution_output'] ?? '');
        } catch (\Throwable $ignored) {
            $sourcecode = '';
            $executionoutput = '';
        }

        return [
            'activityName' => $activity['name'],
            'activityDescription' => $activity['description'],
            'cmid' => (int)$activity['cmid'],
            'grade' => $grade === null ? null : self::format_number($grade),
            'maxGrade' => 100,
            'gradeColorClass' => self::grade_color_class($grade, 100),
            'timesubmittedText' => userdate((int)$record->timesubmitted),
            'timepublishedText' => userdate((int)$record->timepublished),
            'reviewedByTeacher' => $reviewedbyteacher,
            'modifiedByTeacher' => $modifiedbyteacher,
            'originLabel' => get_string(
                $reviewedbyteacher ? 'feedback:originteacher' : 'feedback:originai',
                'local_ai_grading'
            ),
            'originHelp' => get_string(
                $reviewedbyteacher ? 'feedback:originteacherhelp' : 'feedback:originaihelp',
                'local_ai_grading'
            ),
            'generalFeedback' => $generalfeedback,
            'hasGeneralFeedback' => trim((string)$generalfeedback) !== '',
            'criteria' => $criteria,
            'hasCriteria' => !empty($criteria),
            'totalScoreText' => ($grade === null ? '—' : self::format_number($grade)) . ' / 100',
            'sourceCode' => $sourcecode,
            'hasSourceCode' => trim($sourcecode) !== '',
            'executionOutput' => $executionoutput,
            'hasExecutionOutput' => trim($executionoutput) !== '',
        ];
    }

    /**
     * Marks the latest published feedback for a student/VPL activity as read.
     *
     * Called when the student opens the full feedback page. Only the most
     * recently published result (the one surfaced in the assistant) is marked,
     * and only the first time, so the original read timestamp is preserved.
     * When a newer result is published later it starts unread again.
     *
     * @param int $courseid Course id.
     * @param int $userid Student id (the current user).
     * @param int $vplid VPL instance id.
     * @return void
     */
    public static function mark_student_feedback_read(int $courseid, int $userid, int $vplid): void {
        global $DB;

        $sql = "SELECT r.id, r.timestudentviewed
                  FROM {local_ai_grading_result} r
                  JOIN {local_ai_grading_config} cfg ON cfg.id = r.configid
                 WHERE cfg.courseid = :courseid
                   AND cfg.vplid = :vplid
                   AND r.studentid = :userid
                   AND r.timepublished IS NOT NULL
              ORDER BY r.timepublished DESC, r.id DESC";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'vplid' => $vplid,
            'userid' => $userid,
        ], 0, 1);
        $record = $records ? reset($records) : null;
        if ($record && empty($record->timestudentviewed)) {
            $DB->set_field('local_ai_grading_result', 'timestudentviewed', time(), ['id' => (int)$record->id]);
        }
    }

    /**
     * Maps a grade to a pastel colour class (red/amber/green) for the student view.
     *
     * @param float|null $grade Achieved value.
     * @param float $max Maximum value.
     * @return string
     */
    private static function grade_color_class(?float $grade, float $max): string {
        if ($grade === null) {
            return 'aig-grade--none';
        }
        $percent = $max > 0 ? ($grade / $max) * 100 : 0;
        if ($percent >= 80) {
            return 'aig-grade--green';
        }
        if ($percent >= 60) {
            return 'aig-grade--amber';
        }
        return 'aig-grade--red';
    }

    /**
     * Formats a number without trailing zeros (e.g. 78.00 -> "78", 78.5 -> "78.5").
     *
     * @param float $value Value to format.
     * @return string
     */
    private static function format_number(float $value): string {
        $rounded = round($value, 2);
        if ($rounded == (int)$rounded) {
            return (string)(int)$rounded;
        }
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
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
            'manual_references' => self::get_manual_references_for_payload((int)$config->id),
            'submission' => [
                'attempt_no' => (string)$submission['attemptNo'],
                'source_code' => $submission['source_code'],
            ],
        ];
    }

    /**
     * Returns manual teacher grading references for the external AI payload.
     *
     * @param int $configid Config id.
     * @return array
     */
    private static function get_manual_references_for_payload(int $configid): array {
        global $DB;

        $manuals = $DB->get_records(
            'local_ai_grading_manual',
            ['configid' => $configid],
            'timecreated DESC, id DESC',
            '*',
            0,
            3
        );
        if (empty($manuals)) {
            return [];
        }

        $references = [];
        foreach ($manuals as $manual) {
            $details = [];
            $sql = "SELECT mc.criterionid, mc.levelid, mc.observation,
                           c.name AS criterionname, c.weight,
                           l.name AS levelname, l.percentage
                      FROM {local_ai_grading_mancrit} mc
                      JOIN {local_ai_grading_criterion} c ON c.id = mc.criterionid
                      JOIN {local_ai_grading_level} l ON l.id = mc.levelid
                     WHERE mc.manualid = :manualid
                  ORDER BY c.sortorder ASC, c.id ASC";
            $records = $DB->get_records_sql($sql, ['manualid' => (int)$manual->id]);
            foreach ($records as $record) {
                $score = ((float)$record->weight * (float)$record->percentage) / 100;
                $details[] = [
                    'criterion_id' => (string)$record->criterionid,
                    'criterion_name' => (string)$record->criterionname,
                    'level_id' => (string)$record->levelid,
                    'level_name' => (string)$record->levelname,
                    'level_percentage' => (string)$record->percentage,
                    'score' => (string)round($score, 2),
                    'max' => (string)$record->weight,
                    'observation' => (string)$record->observation,
                ];
            }

            $references[] = [
                'manual_id' => (string)$manual->id,
                'student_id' => (string)$manual->studentid,
                'submission_id' => (string)$manual->submissionid,
                'selection_type' => (string)$manual->selectiontype,
                'total_grade' => (string)round((float)$manual->totalgrade, 2),
                'general_observations' => (string)$manual->generalobservations,
                'criteria' => $details,
            ];
        }

        return $references;
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
        $formatted = self::format_manual($record);
        $formatted['details'] = self::get_manual_details($manualid);
        return $formatted;
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

        $records = $DB->get_records_sql($sql, ['configid' => $configid], 0, 3);
        return array_map(static function(\stdClass $record): array {
            $formatted = self::format_manual($record);
            $formatted['details'] = self::get_manual_details((int)$record->id);
            return $formatted;
        }, array_values($records));
    }

    /**
     * Returns manual criterion details.
     *
     * @param int $manualid Manual id.
     * @return array
     */
    private static function get_manual_details(int $manualid): array {
        global $DB;

        $sql = "SELECT mc.id, mc.manualid, mc.criterionid, mc.levelid, mc.observation,
                       c.name AS criterionname, c.weight,
                       l.name AS levelname, l.percentage
                  FROM {local_ai_grading_mancrit} mc
                  JOIN {local_ai_grading_criterion} c ON c.id = mc.criterionid
                  JOIN {local_ai_grading_level} l ON l.id = mc.levelid
                 WHERE mc.manualid = :manualid
              ORDER BY c.sortorder ASC, c.id ASC";
        $records = $DB->get_records_sql($sql, ['manualid' => $manualid]);
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
                'observation' => (string)$record->observation,
            ];
        }
        return $details;
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
     * Creates missing result rows for latest student submissions.
     *
     * @param \stdClass $config Config record.
     * @param array $students Students with submissions.
     * @return void
     */
    private static function ensure_result_records(\stdClass $config, array $students): void {
        global $DB;

        $now = time();
        foreach ($students as $student) {
            $submission = $student['submissions'][0] ?? null;
            if (!$submission) {
                continue;
            }

            $exists = $DB->record_exists('local_ai_grading_result', [
                'configid' => (int)$config->id,
                'submissionid' => (int)$submission['id'],
            ]);
            if ($exists) {
                continue;
            }

            $DB->insert_record('local_ai_grading_result', (object)[
                'configid' => (int)$config->id,
                'studentid' => (int)$student['id'],
                'submissionid' => (int)$submission['id'],
                'attemptnumber' => (int)$submission['attemptNo'],
                'timesubmitted' => (int)$submission['datesubmitted'],
                'aistatus' => 'pending',
                'timecreated' => $now,
            ]);
        }
    }

    /**
     * Returns one persistent result row with criterion details.
     *
     * @param int $resultid Result id.
     * @return array
     */
    private static function get_result(int $resultid): array {
        global $DB;

        $sql = "SELECT r.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username
                  FROM {local_ai_grading_result} r
                  JOIN {user} u ON u.id = r.studentid
                 WHERE r.id = :resultid";
        $record = $DB->get_record_sql($sql, ['resultid' => $resultid], MUST_EXIST);
        $formatted = self::format_result($record);
        $formatted['details'] = self::get_result_details($resultid);
        $formatted['modifiedByTeacher'] = self::details_modified($formatted['details'], $record);
        return $formatted;
    }

    /**
     * Returns persistent result rows for one config.
     *
     * @param int $configid Config id.
     * @return array
     */
    private static function get_results(int $configid): array {
        global $DB;

        $sql = "SELECT r.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username
                  FROM {local_ai_grading_result} r
                  JOIN {user} u ON u.id = r.studentid
                 WHERE r.configid = :configid
              ORDER BY u.lastname, u.firstname, u.id";
        $records = $DB->get_records_sql($sql, ['configid' => $configid]);
        return array_map(static function(\stdClass $record): array {
            $formatted = self::format_result($record);
            $formatted['details'] = self::get_result_details((int)$record->id);
            $formatted['modifiedByTeacher'] = self::details_modified($formatted['details'], $record);
            return $formatted;
        }, array_values($records));
    }

    /**
     * Formats a persistent result row.
     *
     * @param \stdClass $record Result row.
     * @return array
     */
    private static function format_result(\stdClass $record): array {
        $published = !empty($record->timepublished);
        $reviewed = !empty($record->timereviewed);

        return [
            'id' => (int)$record->id,
            'configid' => (int)$record->configid,
            'studentid' => (int)$record->studentid,
            'studentName' => fullname($record),
            'studentUsername' => (string)$record->username,
            'submissionid' => (int)$record->submissionid,
            'attemptnumber' => (int)$record->attemptnumber,
            'timesubmitted' => (int)$record->timesubmitted,
            'timesubmittedText' => userdate((int)$record->timesubmitted),
            'aistatus' => (string)$record->aistatus,
            'aitotalgrade' => $record->aitotalgrade === null ? null : round((float)$record->aitotalgrade, 2),
            'finaltotalgrade' => $record->finaltotalgrade === null ? null : round((float)$record->finaltotalgrade, 2),
            'finalfeedback' => (string)$record->finalfeedback,
            'studentfeedback' => (string)$record->studentfeedback,
            'errordetail' => (string)$record->errordetail,
            'reviewStatus' => $reviewed ? 'approved' : 'pending_review',
            'reviewedByTeacher' => $reviewed,
            'publicationStatus' => $published ? 'published' : 'not_published',
            'timeevaluated' => empty($record->timeevaluated) ? null : (int)$record->timeevaluated,
            'timeevaluatedText' => empty($record->timeevaluated) ? '' : userdate((int)$record->timeevaluated),
            'timereviewed' => empty($record->timereviewed) ? null : (int)$record->timereviewed,
            'timepublished' => empty($record->timepublished) ? null : (int)$record->timepublished,
        ];
    }

    /**
     * Decides whether the teacher changed anything relative to the AI proposal.
     *
     * A result is "modified" only after it has been reviewed and at least one final
     * level, criterion detail, the general feedback or the internal comment differs
     * from what the AI produced.
     *
     * @param array $details Result criterion details (from get_result_details).
     * @param \stdClass $record Result row.
     * @return bool
     */
    private static function details_modified(array $details, \stdClass $record): bool {
        if (empty($record->timereviewed)) {
            return false;
        }

        foreach ($details as $detail) {
            if ($detail['finallevelid'] !== null && $detail['levelid'] !== null
                    && (int)$detail['finallevelid'] !== (int)$detail['levelid']) {
                return true;
            }
            if (trim((string)$detail['finaldetail']) !== trim((string)$detail['detail'])) {
                return true;
            }
        }

        $finalfeedback = trim((string)$record->finalfeedback);
        if ($finalfeedback !== '' && $finalfeedback !== self::baked_ai_feedback($details)) {
            return true;
        }
        if (trim((string)$record->studentfeedback) !== '') {
            return true;
        }

        return false;
    }

    /**
     * Rebuilds the legacy "baked" general comment: the per-criterion AI details joined as
     * "name: detail". Older published results stored exactly this string in finalfeedback
     * before the real AI general_feedback (aifeedback) was kept on its own column. Detecting
     * it lets the student view drop it instead of duplicating the criteria breakdown, and
     * lets details_modified() tell an auto-baked comment apart from a real teacher edit.
     *
     * @param array $details Result criterion details (from get_result_details).
     * @return string
     */
    private static function baked_ai_feedback(array $details): string {
        $parts = [];
        foreach ($details as $detail) {
            $parts[] = $detail['criterionName'] . ': ' . $detail['detail'];
        }
        return trim(implode("\n\n", $parts));
    }

    /**
     * Convenience wrapper of details_modified() given only a result id.
     *
     * @param int $resultid Result id.
     * @return bool
     */
    private static function is_result_modified(int $resultid): bool {
        global $DB;

        $record = $DB->get_record('local_ai_grading_result', ['id' => $resultid]);
        if (!$record) {
            return false;
        }
        return self::details_modified(self::get_result_details($resultid), $record);
    }

    /**
     * Returns result criterion details.
     *
     * @param int $resultid Result id.
     * @return array
     */
    private static function get_result_details(int $resultid): array {
        global $DB;

        $sql = "SELECT d.id, d.resultid, d.criterionid, d.levelid, d.finallevelid, d.aigrade, d.finalgrade,
                       d.aidetail, d.finaldetail, c.name AS criterionname, c.weight,
                       l.name AS levelname, l.percentage,
                       fl.name AS finallevelname, fl.percentage AS finalpercentage
                  FROM {local_ai_grading_rescrit} d
                  JOIN {local_ai_grading_criterion} c ON c.id = d.criterionid
             LEFT JOIN {local_ai_grading_level} l ON l.id = d.levelid
             LEFT JOIN {local_ai_grading_level} fl ON fl.id = d.finallevelid
                 WHERE d.resultid = :resultid
              ORDER BY c.sortorder ASC, c.id ASC";
        $records = $DB->get_records_sql($sql, ['resultid' => $resultid]);
        $details = [];
        foreach ($records as $record) {
            $details[] = [
                'id' => (int)$record->id,
                'criterionid' => (int)$record->criterionid,
                'criterionName' => (string)$record->criterionname,
                'max' => (float)$record->weight,
                // Propuesta de la IA.
                'levelid' => empty($record->levelid) ? null : (int)$record->levelid,
                'levelName' => (string)$record->levelname,
                'percentage' => $record->percentage === null ? null : (float)$record->percentage,
                'score' => $record->aigrade === null ? null : round((float)$record->aigrade, 2),
                'detail' => (string)$record->aidetail,
                // Decisión final del docente (parte igual a la IA).
                'finallevelid' => empty($record->finallevelid) ? null : (int)$record->finallevelid,
                'finalLevelName' => (string)$record->finallevelname,
                'finalpercentage' => $record->finalpercentage === null ? null : (float)$record->finalpercentage,
                'finalscore' => $record->finalgrade === null ? null : round((float)$record->finalgrade, 2),
                'finaldetail' => (string)$record->finaldetail,
            ];
        }
        return $details;
    }

    /**
     * Replaces AI details for one persistent result.
     *
     * @param int $resultid Result id.
     * @param array $criteria Current criteria.
     * @param array $details Normalized AI details.
     * @return void
     */
    private static function replace_result_details(int $resultid, array $criteria, array $details): void {
        global $DB;

        $DB->delete_records('local_ai_grading_rescrit', ['resultid' => $resultid]);
        $map = self::criteria_level_map($criteria);

        foreach ($details as $detail) {
            $criterionid = (int)$detail['criterionid'];
            $levelid = (int)$detail['levelid'];
            $criterion = $map[$criterionid]['criterion'];
            $level = $map[$criterionid]['levels'][$levelid];
            $score = ((float)$criterion['weight'] * (float)$level['percentage']) / 100;

            $DB->insert_record('local_ai_grading_rescrit', (object)[
                'resultid' => $resultid,
                'criterionid' => $criterionid,
                'levelid' => $levelid,
                'finallevelid' => $levelid,
                'aigrade' => round($score, 2),
                'finalgrade' => round($score, 2),
                'aidetail' => (string)$detail['detail'],
                'finaldetail' => (string)$detail['detail'],
            ]);
        }
    }

    /**
     * Builds a compact summary for the result management view.
     *
     * @param int $configid Config id.
     * @return array
     */
    private static function result_summary(int $configid): array {
        $results = self::get_results($configid);
        $summary = [
            'total' => count($results),
            'pending' => 0,
            'processing' => 0,
            'evaluated' => 0,
            'error' => 0,
            'published' => 0,
        ];

        foreach ($results as $result) {
            $status = $result['aistatus'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            if ($result['publicationStatus'] === 'published') {
                $summary['published']++;
            }
        }

        return $summary;
    }

    /**
     * Decides whether the teacher adjusted one criterion relative to the AI proposal.
     *
     * Used to tag criteria in the student view so the AI proposal can be told apart
     * from a teacher change (level chosen or comment rewritten).
     *
     * @param array $detail One row from get_result_details().
     * @return bool
     */
    private static function criterion_adjusted(array $detail): bool {
        $levelchanged = $detail['finallevelid'] !== null && $detail['levelid'] !== null
            && (int)$detail['finallevelid'] !== (int)$detail['levelid'];
        $detailchanged = trim((string)$detail['finaldetail']) !== ''
            && trim((string)$detail['finaldetail']) !== trim((string)$detail['detail']);
        return $levelchanged || $detailchanged;
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
