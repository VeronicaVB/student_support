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

namespace local_student_support\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_student_support\agent\student_support_agent;
use context_course;

defined('MOODLE_INTERNAL') || die();

/**
 * External service for sending messages to the Student Support Agent.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message extends external_api {

    /**
     * Define the parameters for the send_message function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course ID'),
            'sessionid' => new external_value(PARAM_ALPHANUMEXT, 'The session ID'),
            'message' => new external_value(PARAM_RAW, 'The message to send'),
        ]);
    }

    /**
     * Send a message to the Student Support Agent.
     *
     * @param int $courseid The course ID.
     * @param string $sessionid The session ID.
     * @param string $message The message to send.
     * @return array The response from the agent.
     */
    public static function execute(int $courseid, string $sessionid, string $message): array {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sessionid' => $sessionid,
            'message' => $message,
        ]);

        // Get context and check capabilities.
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/student_support:use', $context);

        // Clean the message.
        $cleanmessage = clean_param($params['message'], PARAM_TEXT);
        $cleanmessage = trim($cleanmessage);

        // Validate message is not empty.
        if (empty($cleanmessage)) {
            return [
                'success' => false,
                'message' => get_string('chat:emptymessage', 'local_student_support'),
                'sessionid' => $params['sessionid'],
                'metadata' => json_encode(['error' => 'empty_message']),
            ];
        }

        // Validate message length.
        if (mb_strlen($cleanmessage) > 2000) {
            $cleanmessage = mb_substr($cleanmessage, 0, 2000);
        }

        try {
            // Create agent instance.
            $agent = new student_support_agent(
                $params['courseid'],
                $USER->id,
                $params['sessionid']
            );

            // Check if agent is ready.
            if (!$agent->is_ready()) {
                return [
                    'success' => false,
                    'message' => $agent->get_not_ready_reason(),
                    'sessionid' => $params['sessionid'],
                    'metadata' => json_encode(['error' => 'agent_not_ready']),
                ];
            }

            // Process the message.
            $response = $agent->process_message($cleanmessage);

            return [
                'success' => $response['success'],
                'message' => $response['message'],
                'sessionid' => $agent->get_session_id(),
                'metadata' => json_encode($response['metadata'] ?? []),
            ];

        } catch (\Exception $e) {
            debugging('Student Support Agent error: ' . $e->getMessage(), DEBUG_DEVELOPER);

            return [
                'success' => false,
                'message' => get_string('error:apierror', 'local_student_support'),
                'sessionid' => $params['sessionid'],
                'metadata' => json_encode(['error' => 'exception', 'details' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Define the return structure for the send_message function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the message was processed successfully'),
            'message' => new external_value(PARAM_RAW, 'The response message from the agent'),
            'sessionid' => new external_value(PARAM_ALPHANUMEXT, 'The session ID'),
            'metadata' => new external_value(PARAM_RAW, 'JSON-encoded metadata about the response'),
        ]);
    }
}
