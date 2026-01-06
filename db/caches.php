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
 * Cache definitions for local_student_support.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [

    // Cache for active conversation state.
    // This cache stores the current state of ongoing conversations
    // to reduce database queries during active sessions.
    'conversationstate' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600, // 1 hour TTL.
    ],

    // Cache for agent configuration.
    // Caches site and course-level configuration to reduce database load.
    'agentconfig' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes TTL.
        'invalidationevents' => [
            'local_student_support_config_changed',
        ],
    ],

    // Cache for course-level settings.
    // Caches course-specific configuration.
    'courseconfig' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes TTL.
        'invalidationevents' => [
            'local_student_support_course_config_changed',
        ],
    ],
];
