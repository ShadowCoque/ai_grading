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
            'local_ai_grading_config', //configuracion_evaluacion
            'local_ai_grading_criterion', // criterios
            'local_ai_grading_level', //niveles
            'local_ai_grading_manual', //calificacion manual
            'local_ai_grading_mancrit', //calificacion manual por criterio
            'local_ai_grading_aitest', // prueba IA
            'local_ai_grading_aitcrit', // prueba IA por criterio
            'local_ai_grading_result', // resultados futuros
            'local_ai_grading_rescrit', // resultados por criterio
        ];

        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file($installxml, $tablename, true);
            }
        }

        upgrade_plugin_savepoint(true, 2026051300, 'local', 'ai_grading');
    }

    if ($oldversion < 2026060900) {
        // Track when a student first opened their published feedback, so the
        // floating assistant can show read/unread state.
        $table = new xmldb_table('local_ai_grading_result');
        $field = new xmldb_field('timestudentviewed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timepublished');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026060900, 'local', 'ai_grading');
    }

    if ($oldversion < 2026060901) {
        // rubricfingerprint: detecta cambios críticos de configuración (criterios,
        // niveles, calificaciones manuales) para reiniciar las evaluaciones previas.
        $table = new xmldb_table('local_ai_grading_config');
        $field = new xmldb_field('rubricfingerprint', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'prompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // finallevelid: nivel final elegido por el docente, distinto del sugerido por la IA.
        $table = new xmldb_table('local_ai_grading_rescrit');
        $field = new xmldb_field('finallevelid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'levelid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026060901, 'local', 'ai_grading');
    }

    return true;
}
