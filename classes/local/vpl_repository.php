<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Read-only integration helpers for Moodle VPL data.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides validated access to VPL activities, submissions and source files.
 */
class vpl_repository {
    /** Maximum source/execution text sent to the UI or external services. */
    private const MAX_TEXT_BYTES = 120000;

    /**
     * Returns VPL activities in a course.
     *
     * @param int $courseid Course id.
     * @return array
     */
    public static function get_activities(int $courseid): array {
        global $DB;

        $sql = "SELECT v.id, v.course, v.name, v.intro, v.introformat, cm.id AS cmid, cm.visible
                  FROM {vpl} v
                  JOIN {course_modules} cm ON cm.instance = v.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE v.course = :courseid AND cm.deletioninprogress = 0
              ORDER BY cm.section, cm.id";

        $records = $DB->get_records_sql($sql, [
            'modname' => 'vpl',
            'courseid' => $courseid,
        ]);

        $activities = [];
        foreach ($records as $record) {
            $activities[] = self::format_activity($record);
        }

        return $activities;
    }

    /**
     * Returns one VPL activity after validating course ownership.
     *
     * @param int $courseid Course id.
     * @param int $vplid VPL instance id.
     * @return array
     */
    public static function get_activity(int $courseid, int $vplid): array {
        global $DB;

        $sql = "SELECT v.id, v.course, v.name, v.intro, v.introformat, cm.id AS cmid, cm.visible
                  FROM {vpl} v
                  JOIN {course_modules} cm ON cm.instance = v.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE v.course = :courseid AND v.id = :vplid AND cm.deletioninprogress = 0";

        $record = $DB->get_record_sql($sql, [
            'modname' => 'vpl',
            'courseid' => $courseid,
            'vplid' => $vplid,
        ], MUST_EXIST);

        return self::format_activity($record);
    }

    /**
     * Returns students with VPL submissions grouped with their attempts.
     *
     * @param int $courseid Course id.
     * @param int $vplid VPL instance id.
     * @return array
     */
    public static function get_students_with_submissions(int $courseid, int $vplid): array {
        global $DB;

        self::get_activity($courseid, $vplid);

        $sql = "SELECT s.id, s.vpl, s.userid, s.datesubmitted, s.grade, s.dategraded, s.nevaluations,
                       s.save_count, s.run_count, u.firstname, u.lastname, u.firstnamephonetic,
                       u.lastnamephonetic, u.middlename, u.alternatename, u.username, u.email
                  FROM {vpl_submissions} s
                  JOIN {user} u ON u.id = s.userid
                 WHERE s.vpl = :vplid
              ORDER BY u.lastname, u.firstname, u.id, s.datesubmitted DESC, s.id DESC";

        $records = $DB->get_records_sql($sql, ['vplid' => $vplid]);
        $grouped = [];

        foreach ($records as $record) {
            $userid = (int)$record->userid;
            if (!isset($grouped[$userid])) {
                $grouped[$userid] = [
                    'id' => $userid,
                    'name' => fullname($record),
                    'username' => (string)$record->username,
                    'lastSubmission' => (int)$record->datesubmitted,
                    'lastSubmissionText' => userdate((int)$record->datesubmitted),
                    'attempt' => 0,
                    'aiStatus' => 'not-evaluated',
                    'submissions' => [],
                ];
            }

            $submission = self::format_submission_meta($record, 0);
            $grouped[$userid]['submissions'][] = $submission;
        }

        foreach ($grouped as &$student) {
            $count = count($student['submissions']);
            foreach ($student['submissions'] as $index => &$submission) {
                $submission['attemptNo'] = $count - $index;
            }
            unset($submission);
            $student['attempt'] = $count;
            $student['lastSubmissionId'] = $student['submissions'][0]['id'] ?? 0;
        }
        unset($student);

        return array_values($grouped);
    }

    /**
     * Returns one submission with source files and execution output.
     *
     * @param int $courseid Course id.
     * @param int $vplid VPL instance id.
     * @param int $studentid Student id.
     * @param int $submissionid Submission id.
     * @return array
     */
    public static function get_submission(int $courseid, int $vplid, int $studentid, int $submissionid): array {
        global $DB;

        $activity = self::get_activity($courseid, $vplid);
        $submission = $DB->get_record('vpl_submissions', [
            'id' => $submissionid,
            'vpl' => $vplid,
            'userid' => $studentid,
        ], '*', MUST_EXIST);

        $user = $DB->get_record(
            'user',
            ['id' => $studentid],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, username, email',
            MUST_EXIST
        );
        $attemptno = self::get_attempt_number($vplid, $studentid, $submission);
        $files = self::get_submission_files((int)$activity['cmid'], $vplid, $studentid, $submissionid);
        $execution = self::get_execution_results($vplid, $studentid, $submissionid);

        return [
            'id' => (int)$submission->id,
            'studentid' => $studentid,
            'studentName' => fullname($user),
            'studentUsername' => (string)$user->username,
            'datesubmitted' => (int)$submission->datesubmitted,
            'dateSubmittedText' => userdate((int)$submission->datesubmitted),
            'attemptNo' => $attemptno,
            'grade' => isset($submission->grade) ? (float)$submission->grade : null,
            'files' => $files,
            'source_code' => self::join_files($files),
            'execution_output' => $execution['execution_output'],
            'compilation_output' => $execution['compilation_output'],
            'grade_comments' => $execution['grade_comments'],
            'stdout' => $execution['stdout'],
            'stderr' => $execution['stderr'],
        ];
    }

    /**
     * Validates a submission and returns its record.
     *
     * @param int $courseid Course id.
     * @param int $vplid VPL instance id.
     * @param int $studentid Student id.
     * @param int $submissionid Submission id.
     * @return \stdClass
     */
    public static function validate_submission(int $courseid, int $vplid, int $studentid, int $submissionid): \stdClass {
        global $DB;

        self::get_activity($courseid, $vplid);

        return $DB->get_record('vpl_submissions', [
            'id' => $submissionid,
            'vpl' => $vplid,
            'userid' => $studentid,
        ], '*', MUST_EXIST);
    }

    /**
     * Formats one VPL activity for the browser.
     *
     * @param \stdClass $record Raw VPL record.
     * @return array
     */
    private static function format_activity(\stdClass $record): array {
        $context = \context_module::instance((int)$record->cmid);
        $description = '';

        if (!empty($record->intro)) {
            $description = content_to_text(
                format_text($record->intro, $record->introformat, ['context' => $context]),
                0
            );
        }

        return [
            'id' => (int)$record->id,
            'cmid' => (int)$record->cmid,
            'courseid' => (int)$record->course,
            'name' => format_string($record->name, true, ['context' => $context]),
            'description' => self::truncate_text($description),
            'visible' => (bool)$record->visible,
        ];
    }

    /**
     * Formats one submission row without loading files.
     *
     * @param \stdClass $record Submission row.
     * @param int $attemptno Attempt number.
     * @return array
     */
    private static function format_submission_meta(\stdClass $record, int $attemptno): array {
        return [
            'id' => (int)$record->id,
            'studentid' => (int)$record->userid,
            'datesubmitted' => (int)$record->datesubmitted,
            'dateSubmittedText' => userdate((int)$record->datesubmitted),
            'attemptNo' => $attemptno,
            'grade' => isset($record->grade) ? (float)$record->grade : null,
            'dategraded' => !empty($record->dategraded) ? (int)$record->dategraded : null,
            'evaluations' => isset($record->nevaluations) ? (int)$record->nevaluations : 0,
            'saveCount' => isset($record->save_count) ? (int)$record->save_count : 0,
            'runCount' => isset($record->run_count) ? (int)$record->run_count : 0,
        ];
    }

    /**
     * Calculates the VPL attempt number for one submission.
     *
     * @param int $vplid VPL instance id.
     * @param int $studentid Student id.
     * @param \stdClass $submission Submission record.
     * @return int
     */
    private static function get_attempt_number(int $vplid, int $studentid, \stdClass $submission): int {
        global $DB;

        $sql = "SELECT COUNT(1)
                  FROM {vpl_submissions}
                 WHERE vpl = :vplid
                   AND userid = :userid
                   AND (datesubmitted < :datesubmitted
                        OR (datesubmitted = :datesubmitted2 AND id <= :submissionid))";

        return (int)$DB->count_records_sql($sql, [
            'vplid' => $vplid,
            'userid' => $studentid,
            'datesubmitted' => (int)$submission->datesubmitted,
            'datesubmitted2' => (int)$submission->datesubmitted,
            'submissionid' => (int)$submission->id,
        ]);
    }

    /**
     * Gets files from Moodle file storage, falling back to VPL moodledata layout.
     *
     * @param int $cmid Course module id.
     * @param int $vplid VPL instance id.
     * @param int $studentid Student id.
     * @param int $submissionid Submission id.
     * @return array
     */
    private static function get_submission_files(int $cmid, int $vplid, int $studentid, int $submissionid): array {
        global $CFG;

        $files = [];
        $fs = get_file_storage();
        $contexts = [
            \context_module::instance($cmid)->id,
            \context_system::instance()->id,
        ];

        foreach ($contexts as $contextid) {
            $storedfiles = $fs->get_area_files(
                $contextid,
                'mod_vpl',
                'submission_files',
                $submissionid,
                'filename',
                false
            );

            foreach ($storedfiles as $file) {
                if (!$file->is_directory()) {
                    $files[] = [
                        'filename' => $file->get_filename(),
                        'content' => self::truncate_text($file->get_content()),
                    ];
                }
            }

            if (!empty($files)) {
                return $files;
            }
        }

        $path = $CFG->dataroot . '/vpl_data/' . $vplid . '/usersdata/' . $studentid . '/' . $submissionid . '/submittedfiles/';
        if (!is_dir($path)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }

            $relative = substr($fileinfo->getPathname(), strlen($path));
            $content = file_get_contents($fileinfo->getPathname());
            if ($content !== false) {
                $files[] = [
                    'filename' => $relative,
                    'content' => self::truncate_text($content),
                ];
            }
        }

        return $files;
    }

    /**
     * Reads VPL execution result files if present.
     *
     * @param int $vplid VPL instance id.
     * @param int $studentid Student id.
     * @param int $submissionid Submission id.
     * @return array
     */
    private static function get_execution_results(int $vplid, int $studentid, int $submissionid): array {
        global $CFG;

        $basepath = $CFG->dataroot . '/vpl_data/' . $vplid . '/usersdata/' . $studentid . '/' . $submissionid . '/';
        $execution = self::read_optional_file($basepath . 'execution.txt');
        $compilation = self::read_optional_file($basepath . 'compilation.txt');
        $gradecomments = self::read_optional_file($basepath . 'grade_comments.txt');

        $stdout = null;
        $stderr = null;

        if ($execution !== null && strpos($execution, '--- Program output ---') !== false) {
            $parts = explode('--- Program output ---', $execution, 2);
            $output = $parts[1] ?? '';
            if (strpos($output, '--- Expected output') !== false) {
                $outputparts = explode('--- Expected output', $output, 2);
                $stdout = trim($outputparts[0]);
            } else {
                $stdout = trim($output);
            }
        }

        if (!empty(trim((string)$compilation))) {
            $stderr = $compilation;
        } else if ($execution !== null && (
                strpos($execution, 'Incorrect program output') !== false ||
                strpos($execution, 'Runtime error') !== false
            )) {
            $stderr = $execution;
        }

        return [
            'execution_output' => $execution,
            'compilation_output' => $compilation,
            'grade_comments' => $gradecomments,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Reads a file if it exists.
     *
     * @param string $path File path.
     * @return string|null
     */
    private static function read_optional_file(string $path): ?string {
        if (!is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content === false ? null : self::truncate_text($content);
    }

    /**
     * Joins source files into a readable prompt/UI block.
     *
     * @param array $files Files.
     * @return string
     */
    private static function join_files(array $files): string {
        if (empty($files)) {
            return '';
        }

        $parts = [];
        foreach ($files as $file) {
            $parts[] = '### ' . $file['filename'] . "\n" . $file['content'];
        }

        return self::truncate_text(implode("\n\n", $parts));
    }

    /**
     * Keeps large source blobs bounded.
     *
     * @param string|null $text Source text.
     * @return string
     */
    private static function truncate_text(?string $text): string {
        $text = (string)$text;
        if (strlen($text) <= self::MAX_TEXT_BYTES) {
            return $text;
        }

        return substr($text, 0, self::MAX_TEXT_BYTES) . "\n\n[Contenido truncado para la interfaz]";
    }
}
