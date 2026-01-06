<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for the local_student_support plugin.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_student_support',
        get_string('pluginname', 'local_student_support')
    );

    $ADMIN->add('localplugins', $settings);

    // -------------------------------------------------------------------------
    // General Settings.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_student_support/generalheading',
        get_string('settings:general', 'local_student_support'),
        get_string('settings:general_desc', 'local_student_support')
    ));

    // Enable/Disable plugin.
    $settings->add(new admin_setting_configcheckbox(
        'local_student_support/enabled',
        get_string('settings:enabled', 'local_student_support'),
        get_string('settings:enabled_desc', 'local_student_support'),
        0
    ));

    // -------------------------------------------------------------------------
    // AI Provider Settings.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_student_support/providerheading',
        get_string('settings:provider', 'local_student_support'),
        get_string('settings:provider_desc', 'local_student_support')
    ));

    // API Endpoint.
    $settings->add(new admin_setting_configtext(
        'local_student_support/apiendpoint',
        get_string('settings:apiendpoint', 'local_student_support'),
        get_string('settings:apiendpoint_desc', 'local_student_support'),
        'https://api.openai.com/v1/chat/completions',
        PARAM_URL
    ));

    // API Key.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_student_support/apikey',
        get_string('settings:apikey', 'local_student_support'),
        get_string('settings:apikey_desc', 'local_student_support'),
        ''
    ));

    // Model.
    $settings->add(new admin_setting_configtext(
        'local_student_support/model',
        get_string('settings:model', 'local_student_support'),
        get_string('settings:model_desc', 'local_student_support'),
        'gpt-4',
        PARAM_TEXT
    ));

    // Temperature.
    $settings->add(new admin_setting_configtext(
        'local_student_support/temperature',
        get_string('config:temperature', 'local_student_support'),
        get_string('config:temperature_desc', 'local_student_support'),
        '0.7',
        PARAM_FLOAT
    ));

    // Max tokens.
    $settings->add(new admin_setting_configtext(
        'local_student_support/maxtokens',
        get_string('config:maxtokens', 'local_student_support'),
        get_string('config:maxtokens_desc', 'local_student_support'),
        '1024',
        PARAM_INT
    ));

    // -------------------------------------------------------------------------
    // Curriculum Settings.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_student_support/curriculumheading',
        get_string('settings:curriculum', 'local_student_support'),
        get_string('settings:curriculum_desc', 'local_student_support')
    ));

    // Curriculum name.
    $settings->add(new admin_setting_configtext(
        'local_student_support/curriculumname',
        get_string('settings:curriculumname', 'local_student_support'),
        get_string('settings:curriculumname_desc', 'local_student_support'),
        '',
        PARAM_TEXT
    ));

    // Curriculum year/version.
    $settings->add(new admin_setting_configtext(
        'local_student_support/curriculumyear',
        get_string('settings:curriculumyear', 'local_student_support'),
        get_string('settings:curriculumyear_desc', 'local_student_support'),
        '',
        PARAM_TEXT
    ));

    // Default grade level.
    $settings->add(new admin_setting_configtext(
        'local_student_support/defaultgradelevel',
        get_string('settings:defaultgradelevel', 'local_student_support'),
        get_string('settings:defaultgradelevel_desc', 'local_student_support'),
        '',
        PARAM_TEXT
    ));

    // Subject areas.
    $settings->add(new admin_setting_configtextarea(
        'local_student_support/subjectareas',
        get_string('settings:subjectareas', 'local_student_support'),
        get_string('settings:subjectareas_desc', 'local_student_support'),
        '',
        PARAM_TEXT
    ));

    // -------------------------------------------------------------------------
    // Behaviour Settings.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_student_support/behaviourheading',
        get_string('settings:behaviour', 'local_student_support'),
        get_string('settings:behaviour_desc', 'local_student_support')
    ));

    // Maximum guidance attempts.
    $settings->add(new admin_setting_configtext(
        'local_student_support/maxattempts',
        get_string('settings:maxattempts', 'local_student_support'),
        get_string('settings:maxattempts_desc', 'local_student_support'),
        '5',
        PARAM_INT
    ));

    // Response language.
    $settings->add(new admin_setting_configtext(
        'local_student_support/responselanguage',
        get_string('settings:responselanguage', 'local_student_support'),
        get_string('settings:responselanguage_desc', 'local_student_support'),
        'en',
        PARAM_ALPHANUMEXT
    ));

    // Pedagogical approach.
    $approachoptions = [
        'socratic' => get_string('settings:approach_socratic', 'local_student_support'),
        'scaffolded' => get_string('settings:approach_scaffolded', 'local_student_support'),
        'exploratory' => get_string('settings:approach_exploratory', 'local_student_support'),
    ];

    $settings->add(new admin_setting_configselect(
        'local_student_support/pedagogicalapproach',
        get_string('settings:pedagogicalapproach', 'local_student_support'),
        get_string('settings:pedagogicalapproach_desc', 'local_student_support'),
        'socratic',
        $approachoptions
    ));

    // -------------------------------------------------------------------------
    // Privacy Settings.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_student_support/privacyheading',
        get_string('settings:privacy', 'local_student_support'),
        get_string('settings:privacy_desc', 'local_student_support')
    ));

    // Log conversations.
    $settings->add(new admin_setting_configcheckbox(
        'local_student_support/logconversations',
        get_string('settings:logconversations', 'local_student_support'),
        get_string('settings:logconversations_desc', 'local_student_support'),
        1
    ));

    // Retention period.
    $settings->add(new admin_setting_configtext(
        'local_student_support/retentionperiod',
        get_string('settings:retentionperiod', 'local_student_support'),
        get_string('settings:retentionperiod_desc', 'local_student_support'),
        '365',
        PARAM_INT
    ));

    // Anonymize data.
    $settings->add(new admin_setting_configcheckbox(
        'local_student_support/anonymizedata',
        get_string('settings:anonymizedata', 'local_student_support'),
        get_string('settings:anonymizedata_desc', 'local_student_support'),
        1
    ));
}
