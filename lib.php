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
 * Library functions for local_student_support.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the course settings navigation.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param context $context The context of the page.
 * @return void
 */
function local_student_support_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $PAGE;

    // Only add to course context.
    if ($context->contextlevel !== CONTEXT_COURSE) {
        return;
    }

    // Check capability.
    if (!has_capability('local/student_support:configurecourse', $context)) {
        return;
    }

    // Get the course admin node.
    $courseadminnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);

    if (!$courseadminnode) {
        return;
    }

    // Add our settings link.
    $url = new moodle_url('/local/student_support/course_settings.php', ['id' => $context->instanceid]);
    $node = navigation_node::create(
        get_string('course:settings', 'local_student_support'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'studentsupportsettings',
        new pix_icon('i/settings', '')
    );

    $courseadminnode->add_node($node);
}

/**
 * Check if the plugin is enabled for a specific course.
 *
 * @param int $courseid The course ID.
 * @return bool True if enabled.
 */
function local_student_support_is_enabled_for_course(int $courseid): bool {
    $config = new \local_student_support\agent\agent_config($courseid);
    return $config->is_enabled_for_course();
}

/**
 * Check if a user can use the student support agent.
 *
 * @param int $userid The user ID.
 * @param int $courseid The course ID.
 * @return bool True if user can use the agent.
 */
function local_student_support_can_use(int $userid, int $courseid): bool {
    $context = context_course::instance($courseid);

    if (!has_capability('local/student_support:use', $context, $userid)) {
        return false;
    }

    return local_student_support_is_enabled_for_course($courseid);
}

/**
 * Plugin cron handler - cleanup old data.
 *
 * @return void
 */
function local_student_support_cron(): void {
    $retentiondays = (int) get_config('local_student_support', 'retentionperiod');

    if ($retentiondays > 0) {
        $deleted = \local_student_support\agent\agent_memory::cleanup_old_data($retentiondays);

        if ($deleted > 0) {
            mtrace("local_student_support: Cleaned up {$deleted} old conversation sessions.");
        }
    }
}
