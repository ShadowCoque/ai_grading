<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student feedback page (third interface).
 *
 * Shows the current student their own published AI feedback for one VPL
 * activity. Reachable from the floating assistant, never from the teacher
 * navigation. Access is gated to enrolled users with a published result.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_ai_grading\local\grading_service;

$id = required_param('id', PARAM_INT); // VPL course module id.

[$course, $cm] = get_course_and_cm_from_cmid($id, 'vpl');
$coursecontext = context_course::instance($course->id);
$modulecontext = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('local/ai_grading:viewfeedback', $coursecontext);

$url = new moodle_url('/local/ai_grading/feedback.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($modulecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('feedback:pagetitle', 'local_ai_grading'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('feedback:pagetitle', 'local_ai_grading'), $url);

$PAGE->requires->css(new moodle_url('/local/ai_grading/student.css'));
$PAGE->requires->js_call_amd('local_ai_grading/student', 'init');

$feedback = grading_service::get_student_feedback((int)$course->id, (int)$USER->id, (int)$cm->instance);

echo $OUTPUT->header();

if ($feedback === null) {
    echo $OUTPUT->notification(
        get_string('feedback:notpublished', 'local_ai_grading'),
        \core\output\notification::NOTIFY_INFO
    );
    echo html_writer::link(
        new moodle_url('/mod/vpl/view.php', ['id' => $id]),
        get_string('feedback:backtoactivity', 'local_ai_grading'),
        ['class' => 'btn btn-secondary']
    );
} else {
    $page = new \local_ai_grading\output\student_feedback($feedback);
    echo $OUTPUT->render($page);
}

echo $OUTPUT->footer();
