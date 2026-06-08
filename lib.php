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

/**
 * Loads the floating student assistant assets when the current page qualifies.
 *
 * This runs early (while the navigation is built) so the CSS/JS are queued
 * before the page <head> is sent. The actual panel HTML is injected later by
 * the before_footer_html_generation hook.
 *
 * @param global_navigation $navigation Global navigation (unused).
 * @return void
 */
function local_ai_grading_extend_navigation(global_navigation $navigation): void {
    global $PAGE;

    if (local_ai_grading_student_assistant_context() === null) {
        return;
    }

    $PAGE->requires->css(new moodle_url('/local/ai_grading/student.css'));
    $PAGE->requires->js_call_amd('local_ai_grading/student', 'init');
}

/**
 * Decides whether the floating student assistant should appear on the current page
 * and, if so, returns the data needed to render it.
 *
 * The assistant only shows when:
 *  - the user is logged in (not as guest);
 *  - the page belongs to a real course (not the site home / dashboard);
 *  - the user can view feedback but is not a teacher/manager of the plugin;
 *  - the user has at least one published AI feedback in that course.
 *
 * The result is cached per request so the gating query only runs once.
 *
 * @return array|null Context data, or null when the assistant must not show.
 */
function local_ai_grading_student_assistant_context(): ?array {
    global $PAGE, $USER, $CFG;

    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;

    require_once($CFG->dirroot . '/local/ai_grading/classes/local/grading_service.php');

    if (!isloggedin() || isguestuser() || during_initial_install()) {
        return $cache;
    }
    if (!$PAGE->course || (int)$PAGE->course->id === SITEID) {
        return $cache;
    }
    if ($PAGE->pagelayout === 'login' || $PAGE->pagelayout === 'maintenance') {
        return $cache;
    }

    $coursecontext = context_course::instance((int)$PAGE->course->id);
    if (!has_capability('local/ai_grading:viewfeedback', $coursecontext)
            || has_capability('local/ai_grading:manage', $coursecontext)) {
        return $cache;
    }

    // Restrict to one activity when the current page is a VPL module.
    $vplid = 0;
    if (!empty($PAGE->cm) && $PAGE->cm->modname === 'vpl') {
        $vplid = (int)$PAGE->cm->instance;
    }

    $feedback = \local_ai_grading\local\grading_service::get_student_feedback_list(
        (int)$PAGE->course->id,
        (int)$USER->id
    );
    if (empty($feedback)) {
        return $cache;
    }

    $cache = [
        'courseid' => (int)$PAGE->course->id,
        'currentvplid' => $vplid,
        'feedback' => $feedback,
    ];
    return $cache;
}
