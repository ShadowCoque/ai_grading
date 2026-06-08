<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Renderable for the floating student assistant panel.
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
 * Exports the published feedback list to the floating assistant template.
 */
class student_assistant implements renderable, templatable {
    /** @var array List of published feedback entries. */
    private $feedback;

    /** @var int VPL instance of the current page, when it is a VPL activity. */
    private $currentvplid;

    /**
     * @param array $feedback Published feedback entries.
     * @param int $currentvplid VPL instance id of the current page (0 if none).
     */
    public function __construct(array $feedback, int $currentvplid = 0) {
        $this->feedback = $feedback;
        $this->currentvplid = $currentvplid;
    }

    /**
     * Provides template data.
     *
     * @param renderer_base $output Renderer instance.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $USER;

        // Flag the activity matching the current page and bubble it to the top.
        $items = [];
        foreach ($this->feedback as $item) {
            $item['isCurrent'] = $this->currentvplid > 0 && (int)$item['vplid'] === $this->currentvplid;
            $items[] = $item;
        }
        usort($items, static function(array $a, array $b): int {
            return ($b['isCurrent'] ? 1 : 0) <=> ($a['isCurrent'] ? 1 : 0);
        });

        return [
            'firstname' => $USER->firstname,
            'count' => count($items),
            'hasmany' => count($items) > 1,
            'feedback' => $items,
        ];
    }
}
