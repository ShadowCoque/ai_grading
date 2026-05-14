<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Navigation callbacks for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds AI Grading to the course navigation.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The current course.
 * @param context $context The course context.
 * @return void
 */
function local_ai_grading_extend_navigation_course($navigation, $course, $context): void {
    global $PAGE;

    if ((int)$course->id === SITEID || !has_capability('local/ai_grading:manage', $context)) {
        return;
    }

    $url = new moodle_url('/local/ai_grading/index.php', ['courseid' => $course->id]);
    $node = navigation_node::create(
        get_string('pluginname', 'local_ai_grading'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_ai_grading',
        new pix_icon('i/grades', '')
    );

    if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
        $node->make_active();
    }

    $navigation->add_node($node);
}
