<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX service definitions for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ai_grading_request' => [
        'classname' => 'local_ai_grading\external\request',
        'methodname' => 'execute',
        'classpath' => 'local/ai_grading/classes/external/request.php',
        'description' => 'Dispatch AI Grading teacher setup AJAX actions',
        'type' => 'write',
        'ajax' => true,
    ],
];
