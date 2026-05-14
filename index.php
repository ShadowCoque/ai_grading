<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Main teacher setup page for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
$url = new moodle_url('/local/ai_grading/index.php', ['courseid' => $courseid]);

require_login($course);
require_capability('local/ai_grading:manage', $context);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pagetitle', 'local_ai_grading'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('pluginname', 'local_ai_grading'), $url);

$PAGE->requires->css(new moodle_url('/local/ai_grading/styles.css'));
$PAGE->requires->js_call_amd('local_ai_grading/teacher_setup', 'init');

$page = new \local_ai_grading\output\teacher_setup($course);

echo $OUTPUT->header();
echo $OUTPUT->render($page);
echo $OUTPUT->footer();
