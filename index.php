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
 * Student Support Agent chat interface entry point.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Get course ID from URL parameter.
$courseid = required_param('courseid', PARAM_INT);

// Require login and course context.
require_login($courseid);

$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check capability.
require_capability('local/student_support:use', $context);

// Check if plugin is enabled.
if (!get_config('local_student_support', 'enabled')) {
    throw new moodle_exception('error:notconfigured', 'local_student_support');
}

// Check if enabled for this course.
if (!local_student_support_is_enabled_for_course($courseid)) {
    throw new moodle_exception('error:coursenotconfigured', 'local_student_support');
}

// Set up page.
$PAGE->set_url(new moodle_url('/local/student_support/index.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'local_student_support'));
$PAGE->set_heading($course->fullname);

// Add navbar.
$PAGE->navbar->add(get_string('pluginname', 'local_student_support'));

// Get or create session ID.
$sessionid = optional_param('session', null, PARAM_ALPHANUMEXT);
if (empty($sessionid)) {
    $sessionid = \local_student_support\agent\agent_memory::generate_session_id($USER->id, $courseid);
}

// Prepare template context.
$templatecontext = [
    'courseid' => $courseid,
    'sessionid' => $sessionid,
    'userid' => $USER->id,
    'username' => fullname($USER),
    'userinitials' => mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1),
    'welcomemessage' => get_string('message:welcome', 'local_student_support'),
    'placeholdertext' => get_string('chat:placeholder', 'local_student_support'),
    'sendlabel' => get_string('chat:send', 'local_student_support'),
    'typingindicator' => get_string('chat:typing', 'local_student_support'),
    'coursename' => $course->shortname,
];

// Initialize AMD module.
$PAGE->requires->js_call_amd('local_student_support/chat', 'init', [
    $courseid,
    $sessionid,
    $USER->id,
]);

// Output page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_student_support/chat', $templatecontext);
echo $OUTPUT->footer();
