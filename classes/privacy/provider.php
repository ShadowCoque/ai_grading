<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy provider for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;

/**
 * Describes data stored by the plugin.
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Returns metadata about stored data.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_ai_grading_config', [
            'courseid' => 'privacy:metadata:courseid',
            'vplid' => 'privacy:metadata:vplid',
            'teacherid' => 'privacy:metadata:teacherid',
            'prompt' => 'privacy:metadata:observations',
        ], 'privacy:metadata:local_ai_grading_config');

        $collection->add_database_table('local_ai_grading_manual', [
            'studentid' => 'privacy:metadata:studentid',
            'submissionid' => 'privacy:metadata:submissionid',
            'totalgrade' => 'privacy:metadata:grades',
            'generalobservations' => 'privacy:metadata:observations',
        ], 'privacy:metadata:local_ai_grading_manual');

        $collection->add_database_table('local_ai_grading_mancrit', [
            'observation' => 'privacy:metadata:observations',
        ], 'privacy:metadata:local_ai_grading_mancrit');

        $collection->add_database_table('local_ai_grading_aitest', [
            'studentid' => 'privacy:metadata:studentid',
            'submissionid' => 'privacy:metadata:submissionid',
            'totalgrade' => 'privacy:metadata:grades',
            'generalfeedback' => 'privacy:metadata:observations',
        ], 'privacy:metadata:local_ai_grading_aitest');

        $collection->add_database_table('local_ai_grading_aitcrit', [
            'aidetail' => 'privacy:metadata:observations',
        ], 'privacy:metadata:local_ai_grading_aitcrit');

        $collection->add_database_table('local_ai_grading_result', [
            'studentid' => 'privacy:metadata:studentid',
            'submissionid' => 'privacy:metadata:submissionid',
            'aitotalgrade' => 'privacy:metadata:grades',
            'finaltotalgrade' => 'privacy:metadata:grades',
            'finalfeedback' => 'privacy:metadata:observations',
            'studentfeedback' => 'privacy:metadata:observations',
            'reviewedby' => 'privacy:metadata:teacherid',
            'publishedby' => 'privacy:metadata:teacherid',
        ], 'privacy:metadata:local_ai_grading_result');

        $collection->add_database_table('local_ai_grading_rescrit', [
            'aigrade' => 'privacy:metadata:grades',
            'finalgrade' => 'privacy:metadata:grades',
            'aidetail' => 'privacy:metadata:observations',
            'finaldetail' => 'privacy:metadata:observations',
        ], 'privacy:metadata:local_ai_grading_rescrit');

        return $collection;
    }
}
