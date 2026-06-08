<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Renderable for the student feedback page (third interface).
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;

/**
 * Exports one student's published feedback to the Mustache template.
 */
class student_feedback implements renderable, templatable {
    /** @var array Feedback payload from grading_service::get_student_feedback. */
    private $feedback;

    /**
     * @param array $feedback Feedback payload.
     */
    public function __construct(array $feedback) {
        $this->feedback = $feedback;
    }

    /**
     * Provides template data.
     *
     * @param renderer_base $output Renderer instance.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return $this->feedback;
    }
}
