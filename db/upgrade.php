<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade steps for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Runs plugin database upgrades.
 *
 * @param int $oldversion Previously installed version.
 * @return bool
 */
function xmldb_local_ai_grading_upgrade($oldversion): bool {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051300) {
        $installxml = $CFG->dirroot . '/local/ai_grading/db/install.xml';
        $tables = [
            'local_ai_grading_config',
            'local_ai_grading_criterion',
            'local_ai_grading_level',
            'local_ai_grading_manual',
            'local_ai_grading_mancrit',
            'local_ai_grading_aitest',
            'local_ai_grading_aitcrit',
            'local_ai_grading_result',
            'local_ai_grading_rescrit',
        ];

        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file($installxml, $tablename, true);
            }
        }

        upgrade_plugin_savepoint(true, 2026051300, 'local', 'ai_grading');
    }

    return true;
}
