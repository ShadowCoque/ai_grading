<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX request dispatcher for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_ai_grading\local\grading_service;

/**
 * Single AJAX entrypoint for teacher setup actions.
 */
class request extends external_api {
    /**
     * Describes input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'action' => new external_value(PARAM_TEXT, 'Action name'),
            'payload' => new external_value(PARAM_RAW, 'JSON payload', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Executes the requested action.
     *
     * @param int $courseid Course id.
     * @param string $action Action name.
     * @param string $payload JSON payload.
     * @return array
     */
    public static function execute(int $courseid, string $action, string $payload = '{}'): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'action' => $action,
            'payload' => $payload,
        ]);

        $course = get_course((int)$params['courseid']);
        $context = \context_course::instance((int)$course->id);
        require_login($course);
        self::validate_context($context);
        require_capability('local/ai_grading:manage', $context);

        $decoded = json_decode($params['payload'], true);
        if (!is_array($decoded)) {
            return self::response(false, get_string('invalidjson', 'local_ai_grading'));
        }

        try {
            $data = self::dispatch((int)$course->id, (string)$params['action'], $decoded, (int)$USER->id);
            return self::response(true, '', $data);
        } catch (\moodle_exception $exception) {
            return self::response(false, $exception->getMessage());
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER, $exception->getTrace());
            return self::response(false, get_string('unexpectederror', 'local_ai_grading'));
        }
    }

    /**
     * Dispatches whitelisted actions.
     *
     * @param int $courseid Course id.
     * @param string $action Action.
     * @param array $payload Payload.
     * @param int $teacherid Teacher id.
     * @return array
     */
    private static function dispatch(int $courseid, string $action, array $payload, int $teacherid): array {
        switch ($action) {
            case 'get_state':
                return grading_service::get_state($courseid, (int)($payload['vplid'] ?? 0));

            case 'save_config':
                return grading_service::save_configuration($courseid, $payload, $teacherid);

            case 'get_submission':
                return grading_service::get_submission($courseid, $payload);

            case 'save_manual':
                return grading_service::save_manual($courseid, $payload);

            case 'delete_manual':
                return grading_service::delete_manual($courseid, $payload);

            case 'run_ai_test':
                return grading_service::run_ai_test($courseid, $payload, $teacherid);

            case 'delete_ai_test':
                return grading_service::delete_ai_test($courseid, $payload);

            case 'get_results_state':
                return grading_service::get_results_state($courseid, $payload);

            case 'run_result_ai':
                return grading_service::run_result_ai($courseid, $payload, $teacherid);

            case 'save_result_review':
                return grading_service::save_result_review($courseid, $payload, $teacherid);

            case 'publish_result':
                return grading_service::publish_result($courseid, $payload, $teacherid);

            default:
                throw new \moodle_exception('unknownaction', 'local_ai_grading');
        }
    }

    /**
     * Builds the external response.
     *
     * @param bool $success Success flag.
     * @param string $message Message.
     * @param array $data Data.
     * @return array
     */
    private static function response(bool $success, string $message = '', array $data = []): array {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'success' => $success,
            'message' => $message,
            'data' => $json === false ? '{}' : $json,
        ];
    }

    /**
     * Describes return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'message' => new external_value(PARAM_RAW, 'Message'),
            'data' => new external_value(PARAM_RAW, 'JSON encoded response data'),
        ]);
    }
}
