<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AI service adapter for mock and external modes.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Runs an AI grading test through mock data or an external webhook.
 */
class ai_client {
    /**
     * Executes an AI test according to plugin settings.
     *
     * @param array $payload External-service payload.
     * @param array $criteria Criteria with levels.
     * @return array Normalized AI response.
     */
    public static function run(array $payload, array $criteria): array {
        $mode = get_config('local_ai_grading', 'mode') ?: 'mock';
        $url = trim((string)get_config('local_ai_grading', 'service_url'));
        $token = trim((string)get_config('local_ai_grading', 'service_token'));
        $timeout = (int)(get_config('local_ai_grading', 'timeout') ?: 30);
        $timeout = max(5, min(120, $timeout));

        if ($mode !== 'external') {
            return self::mock_response($criteria, (int)$payload['submission_id'], 'mock');
        }

        if ($url === '' || $token === '') {
            $response = self::mock_response($criteria, (int)$payload['submission_id'], 'mock');
            $response['message'] = get_string('externalfallbackmissingconfig', 'local_ai_grading');
            return $response;
        }

        $external = self::external_response($url, $token, $timeout, $payload, $criteria);
        $external['mode'] = 'external';
        return $external;
    }

    /**
     * Returns a deterministic simulated response.
     *
     * @param array $criteria Criteria with levels.
     * @param int $submissionid Submission id.
     * @param string $mode Effective mode.
     * @return array
     */
    private static function mock_response(array $criteria, int $submissionid, string $mode): array {
        $results = [];
        $total = 0.0;

        foreach ($criteria as $criterion) {
            $levels = $criterion['levels'] ?? [];
            usort($levels, static function(array $a, array $b): int {
                return ((float)$b['percentage'] <=> (float)$a['percentage']);
            });

            if (empty($levels)) {
                continue;
            }

            $pick = ($submissionid + (int)$criterion['id']) % count($levels);
            $level = $levels[$pick];
            $score = ((float)$criterion['weight'] * (float)$level['percentage']) / 100;
            $total += $score;

            $results[] = [
                'criterion_id' => (int)$criterion['id'],
                'level_id' => (int)$level['id'],
                'detail' => get_string('mockcriteriadetail', 'local_ai_grading', (object)[
                    'criterion' => $criterion['name'],
                    'level' => $level['name'],
                    'percentage' => self::format_number((float)$level['percentage']),
                ]),
            ];
        }

        return [
            'mode' => $mode,
            'total_grade' => round($total, 2),
            'general_feedback' => get_string('mockgeneralfeedback', 'local_ai_grading'),
            'criteria' => $results,
            'message' => get_string('mockresultnotice', 'local_ai_grading'),
        ];
    }

    /**
     * Calls the configured external webhook and normalizes the response.
     *
     * @param string $url Endpoint URL.
     * @param string $token API token.
     * @param int $timeout Request timeout.
     * @param array $payload JSON payload.
     * @param array $criteria Criteria with levels for validation.
     * @return array
     */
    private static function external_response(
        string $url,
        string $token,
        int $timeout,
        array $payload,
        array $criteria
    ): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \moodle_exception('invalidpayload', 'local_ai_grading');
        }

        $curl = new \curl();
        $authorization = stripos($token, 'bearer ') === 0 ? $token : 'Bearer ' . $token;
        $curl->setHeader([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . $authorization,
            'X-API-Key: ' . $token,
        ]);

        $rawresponse = $curl->post($url, $json, [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(10, $timeout),
        ]);

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;

        if ($curl->get_errno() || $httpcode < 200 || $httpcode >= 300) {
            $detail = $curl->error ?: $rawresponse;
            throw new \moodle_exception('externalrequestfailed', 'local_ai_grading', '', (object)[
                'httpcode' => $httpcode,
                'detail' => clean_param((string)$detail, PARAM_TEXT),
            ]);
        }

        $decoded = json_decode((string)$rawresponse, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('externalinvalidjson', 'local_ai_grading');
        }

        return self::normalize_external_response($decoded, $criteria);
    }

    /**
     * Normalizes and validates a webhook response.
     *
     * @param array $decoded Raw decoded response.
     * @param array $criteria Criteria with levels.
     * @return array
     */
    private static function normalize_external_response(array $decoded, array $criteria): array {
        $criterionmap = [];
        foreach ($criteria as $criterion) {
            $levelids = [];
            foreach ($criterion['levels'] ?? [] as $level) {
                $levelids[(int)$level['id']] = true;
            }
            $criterionmap[(int)$criterion['id']] = [
                'criterion' => $criterion,
                'levelids' => $levelids,
            ];
        }

        $items = $decoded['criteria'] ?? [];
        if (!is_array($items)) {
            throw new \moodle_exception('externalinvalidresponse', 'local_ai_grading');
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $criterionid = (int)($item['criterion_id'] ?? 0);
            $levelid = (int)($item['level_id'] ?? 0);
            if (!isset($criterionmap[$criterionid]) || !isset($criterionmap[$criterionid]['levelids'][$levelid])) {
                throw new \moodle_exception('externalinvalidlevel', 'local_ai_grading');
            }

            $normalized[] = [
                'criterion_id' => $criterionid,
                'level_id' => $levelid,
                'detail' => clean_param((string)($item['detail'] ?? ''), PARAM_TEXT),
            ];
        }

        if (empty($normalized)) {
            throw new \moodle_exception('externalinvalidresponse', 'local_ai_grading');
        }

        $total = isset($decoded['total_grade']) && is_numeric($decoded['total_grade'])
            ? (float)$decoded['total_grade']
            : self::calculate_total($criteria, $normalized);

        return [
            'total_grade' => round($total, 2),
            'general_feedback' => clean_param((string)($decoded['general_feedback'] ?? ''), PARAM_TEXT),
            'criteria' => $normalized,
            'message' => get_string('externalresultnotice', 'local_ai_grading'),
        ];
    }

    /**
     * Calculates a total grade from selected levels.
     *
     * @param array $criteria Criteria with levels.
     * @param array $results Criterion result rows.
     * @return float
     */
    private static function calculate_total(array $criteria, array $results): float {
        $criteriabyid = [];
        foreach ($criteria as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] ?? [] as $level) {
                $levels[(int)$level['id']] = (float)$level['percentage'];
            }
            $criteriabyid[(int)$criterion['id']] = [
                'weight' => (float)$criterion['weight'],
                'levels' => $levels,
            ];
        }

        $total = 0.0;
        foreach ($results as $result) {
            $criterionid = (int)$result['criterion_id'];
            $levelid = (int)$result['level_id'];
            if (!isset($criteriabyid[$criterionid]['levels'][$levelid])) {
                continue;
            }
            $total += ($criteriabyid[$criterionid]['weight'] * $criteriabyid[$criterionid]['levels'][$levelid]) / 100;
        }

        return $total;
    }

    /**
     * Formats a number for strings.
     *
     * @param float $number Number.
     * @return string
     */
    private static function format_number(float $number): string {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
