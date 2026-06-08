<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Injects the floating student assistant before the footer.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_ai_grading\hook\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the floating assistant panel HTML to qualifying pages.
 */
class before_footer_html_generation {
    /**
     * Renders and injects the student assistant when the current page qualifies.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The footer hook.
     * @return void
     */
    public static function execute(\core\hook\output\before_footer_html_generation $hook): void {
        global $OUTPUT, $CFG;

        require_once($CFG->dirroot . '/local/ai_grading/lib.php');

        $context = local_ai_grading_student_assistant_context();
        if ($context === null) {
            return;
        }

        $panel = new \local_ai_grading\output\student_assistant(
            $context['feedback'],
            $context['currentvplid']
        );
        $hook->add_html($OUTPUT->render($panel));
    }
}
