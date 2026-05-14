<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Renderable page data for the teacher setup interface.
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
 * Exports Moodle context and UI strings to the Mustache template.
 */
class teacher_setup implements renderable, templatable {
    /** @var \stdClass */
    private $course;

    /**
     * @param \stdClass $course Current course.
     */
    public function __construct(\stdClass $course) {
        $this->course = $course;
    }

    /**
     * Provides template data.
     *
     * @param renderer_base $output Renderer instance.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $context = \context_course::instance($this->course->id);
        $labels = [
            'activityLabel' => get_string('activitylabel', 'local_ai_grading'),
            'activityHelp' => get_string('activityhelp', 'local_ai_grading'),
            'activityPreview' => get_string('activitypreview', 'local_ai_grading'),
            'addCriterion' => get_string('addcriterion', 'local_ai_grading'),
            'addLevel' => get_string('addlevel', 'local_ai_grading'),
            'aiCase' => get_string('aicase', 'local_ai_grading'),
            'aiDiscard' => get_string('aidiscard', 'local_ai_grading'),
            'aiFeedback' => get_string('aifeedback', 'local_ai_grading'),
            'aiKeep' => get_string('aikeep', 'local_ai_grading'),
            'aiRun' => get_string('airun', 'local_ai_grading'),
            'aiScore' => get_string('aiscore', 'local_ai_grading'),
            'aiTotal' => get_string('aitotal', 'local_ai_grading'),
            'applyPlaceholder' => get_string('applyplaceholder', 'local_ai_grading'),
            'active' => get_string('active', 'local_ai_grading'),
            'criteriaTotal' => get_string('criteriatotal', 'local_ai_grading'),
            'criterionDescription' => get_string('criteriondescription', 'local_ai_grading'),
            'criterionName' => get_string('criterionname', 'local_ai_grading'),
            'delete' => get_string('delete', 'local_ai_grading'),
            'description' => get_string('description', 'local_ai_grading'),
            'discard' => get_string('discard', 'local_ai_grading'),
            'editInline' => get_string('editinline', 'local_ai_grading'),
            'history' => get_string('history', 'local_ai_grading'),
            'keepAsActive' => get_string('keepasactive', 'local_ai_grading'),
            'levelName' => get_string('levelname', 'local_ai_grading'),
            'loadCase' => get_string('loadcase', 'local_ai_grading'),
            'manualNew' => get_string('manualnew', 'local_ai_grading'),
            'manualObservations' => get_string('manualobservations', 'local_ai_grading'),
            'manualSave' => get_string('manualsave', 'local_ai_grading'),
            'manualScore' => get_string('manualscore', 'local_ai_grading'),
            'manualSelectMode' => get_string('manualselectmode', 'local_ai_grading'),
            'manualSpecificStudent' => get_string('manualspecificstudent', 'local_ai_grading'),
            'manualRandomStudent' => get_string('manualrandomstudent', 'local_ai_grading'),
            'minPercent' => get_string('minpercent', 'local_ai_grading'),
            'moveDown' => get_string('movedown', 'local_ai_grading'),
            'moveUp' => get_string('moveup', 'local_ai_grading'),
            'newReference' => get_string('newreference', 'local_ai_grading'),
            'noActiveCase' => get_string('noactivecase', 'local_ai_grading'),
            'noHistory' => get_string('nohistory', 'local_ai_grading'),
            'noAiResult' => get_string('noairesult', 'local_ai_grading'),
            'pendingResult' => get_string('pendingresult', 'local_ai_grading'),
            'percent' => get_string('percent', 'local_ai_grading'),
            'promptPreview' => get_string('promptpreview', 'local_ai_grading'),
            'savedNotice' => get_string('savednotice', 'local_ai_grading'),
            'simulated' => get_string('simulated', 'local_ai_grading'),
            'student' => get_string('student', 'local_ai_grading'),
            'testKept' => get_string('testkept', 'local_ai_grading'),
            'weight' => get_string('weight', 'local_ai_grading'),
        ];

        return [
            'courseid' => $this->course->id,
            'coursename' => format_string($this->course->fullname, true, ['context' => $context]),
            'labelsjson' => json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
    }
}
