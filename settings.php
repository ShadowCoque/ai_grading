<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site administration settings for AI Grading.
 *
 * @package    local_ai_grading
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ai_grading', get_string('pluginname', 'local_ai_grading'));

    $settings->add(new admin_setting_configselect(
        'local_ai_grading/mode',
        get_string('settings:mode', 'local_ai_grading'),
        get_string('settings:mode_desc', 'local_ai_grading'),
        'mock',
        [
            'mock' => get_string('settings:mode_mock', 'local_ai_grading'),
            'external' => get_string('settings:mode_external', 'local_ai_grading'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_grading/service_url',
        get_string('settings:service_url', 'local_ai_grading'),
        get_string('settings:service_url_desc', 'local_ai_grading'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_grading/service_token',
        get_string('settings:service_token', 'local_ai_grading'),
        get_string('settings:service_token_desc', 'local_ai_grading'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ai_grading/timeout',
        get_string('settings:timeout', 'local_ai_grading'),
        get_string('settings:timeout_desc', 'local_ai_grading'),
        30,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
