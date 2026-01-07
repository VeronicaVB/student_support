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

namespace local_student_support\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Function call handler for the Student Support Agent.
 *
 * Processes tool calls from OpenAI and prepares them for agent execution.
 *
 * This handler:
 * - Validates tool calls against the registry
 * - Extracts and validates arguments
 * - Returns structured data for the agent orchestrator
 *
 * This handler NEVER:
 * - Executes actions directly
 * - Makes behavioral decisions
 * - Calls external services
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class function_call_handler {

    /** @var tool_registry Tool registry instance. */
    private tool_registry $registry;

    /**
     * Constructor.
     *
     * @param tool_registry|null $registry Tool registry (null = create new).
     */
    public function __construct(?tool_registry $registry = null) {
        $this->registry = $registry ?? new tool_registry();
    }

    /**
     * Handle an OpenAI API response.
     *
     * @param array $response Response from openai_client::ask().
     * @return array Processed result with type and data.
     */
    public function handle_response(array $response): array {
        switch ($response['type']) {
            case openai_client::RESPONSE_TEXT:
                return $this->handle_text_response($response);

            case openai_client::RESPONSE_TOOL_CALL:
                return $this->handle_tool_call($response);

            case openai_client::RESPONSE_ERROR:
                return $this->handle_error($response);

            default:
                return [
                    'type' => 'error',
                    'error' => 'Unknown response type: ' . $response['type'],
                    'metadata' => $response['metadata'] ?? [],
                ];
        }
    }

    /**
     * Handle a text response.
     *
     * @param array $response Text response from API.
     * @return array Processed result.
     */
    private function handle_text_response(array $response): array {
        return [
            'type' => 'text',
            'content' => $response['content'],
            'action' => null,
            'arguments' => [],
            'metadata' => $response['metadata'] ?? [],
        ];
    }

    /**
     * Handle a tool call response.
     *
     * @param array $response Tool call response from API.
     * @return array Processed result.
     */
    private function handle_tool_call(array $response): array {
        $toolcall = $response['tool_call'];
        $toolname = $toolcall['name'];
        $arguments = $toolcall['arguments'] ?? [];

        // Validate the tool call.
        $validation = $this->registry->validate_tool_call($toolname, $arguments);

        if (!$validation['valid']) {
            return [
                'type' => 'error',
                'error' => 'Invalid tool call: ' . implode(', ', $validation['errors']),
                'action' => $toolname,
                'arguments' => $arguments,
                'metadata' => $response['metadata'] ?? [],
            ];
        }

        // Check if this is a special tool (no action class).
        if (!$this->registry->tool_has_action($toolname)) {
            return $this->handle_special_tool($toolname, $arguments, $response['metadata'] ?? []);
        }

        // Return the validated tool call for the agent to execute.
        return [
            'type' => 'tool_call',
            'action' => $toolname,
            'action_class' => $this->registry->get_action_class($toolname),
            'arguments' => $arguments,
            'tool_call_id' => $toolcall['id'] ?? null,
            'metadata' => $response['metadata'] ?? [],
        ];
    }

    /**
     * Handle special tools that don't map to action classes.
     *
     * @param string $toolname Tool name.
     * @param array $arguments Tool arguments.
     * @param array $metadata Response metadata.
     * @return array Processed result.
     */
    private function handle_special_tool(string $toolname, array $arguments, array $metadata): array {
        switch ($toolname) {
            case 'respond_directly':
                return [
                    'type' => 'direct_response',
                    'action' => $toolname,
                    'response_type' => $arguments['response_type'] ?? 'acknowledgment',
                    'content' => $arguments['message'] ?? '',
                    'arguments' => $arguments,
                    'metadata' => $metadata,
                ];

            default:
                return [
                    'type' => 'error',
                    'error' => "Unhandled special tool: {$toolname}",
                    'metadata' => $metadata,
                ];
        }
    }

    /**
     * Handle an error response.
     *
     * @param array $response Error response from API.
     * @return array Processed error result.
     */
    private function handle_error(array $response): array {
        return [
            'type' => 'error',
            'error' => $response['error'] ?? 'Unknown error',
            'action' => null,
            'arguments' => [],
            'metadata' => $response['metadata'] ?? [],
        ];
    }

    /**
     * Get the tool registry.
     *
     * @return tool_registry The registry instance.
     */
    public function get_registry(): tool_registry {
        return $this->registry;
    }

    /**
     * Check if a response indicates a tool call.
     *
     * @param array $response Response from openai_client::ask().
     * @return bool True if response is a tool call.
     */
    public static function is_tool_call(array $response): bool {
        return ($response['type'] ?? '') === openai_client::RESPONSE_TOOL_CALL;
    }

    /**
     * Check if a response indicates an error.
     *
     * @param array $response Response from openai_client::ask().
     * @return bool True if response is an error.
     */
    public static function is_error(array $response): bool {
        return ($response['type'] ?? '') === openai_client::RESPONSE_ERROR;
    }

    /**
     * Check if a processed result requires action execution.
     *
     * @param array $result Processed result from handle_response().
     * @return bool True if an action should be executed.
     */
    public static function requires_action_execution(array $result): bool {
        return $result['type'] === 'tool_call' && !empty($result['action_class']);
    }

    /**
     * Check if a processed result is a direct response.
     *
     * @param array $result Processed result from handle_response().
     * @return bool True if this is a direct response.
     */
    public static function is_direct_response(array $result): bool {
        return $result['type'] === 'direct_response';
    }
}
