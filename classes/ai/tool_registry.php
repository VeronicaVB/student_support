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
 * Tool registry for the Student Support Agent.
 *
 * Maps agent actions to OpenAI function calling tools.
 * Each tool corresponds to an existing PHP action class.
 *
 * This registry:
 * - Defines tool schemas for OpenAI
 * - Validates tool names
 * - Maps tools to action classes
 *
 * This registry NEVER:
 * - Executes actions
 * - Makes behavioral decisions
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_registry {

    /** @var array Registered tools. */
    private array $tools;

    /** @var array Map of tool names to action class names. */
    private array $actionmap;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->tools = [];
        $this->actionmap = [];
        $this->register_default_tools();
    }

    /**
     * Register the default agent tools.
     *
     * @return void
     */
    private function register_default_tools(): void {
        // Tool: explain_concept.
        $this->register_tool(
            'explain_concept',
            'local_student_support\agent\actions\explain_concept',
            'Explain a concept or topic to help the student understand. Use this when the student needs ' .
            'a clear explanation of an idea, term, or process. Break down complex ideas into simpler parts. ' .
            'NEVER provide complete solutions or answers to assessable tasks.',
            [
                'type' => 'object',
                'properties' => [
                    'concept' => [
                        'type' => 'string',
                        'description' => 'The specific concept or topic to explain.',
                    ],
                    'depth' => [
                        'type' => 'string',
                        'enum' => ['basic', 'intermediate', 'detailed'],
                        'description' => 'The depth of explanation needed based on student understanding.',
                    ],
                    'connect_to' => [
                        'type' => 'string',
                        'description' => 'Optional: A concept the student already knows to connect this explanation to.',
                    ],
                ],
                'required' => ['concept'],
            ]
        );

        // Tool: ask_guiding_question.
        $this->register_tool(
            'ask_guiding_question',
            'local_student_support\agent\actions\ask_guiding_question',
            'Ask a guiding question to help the student think through a problem. Use this to promote ' .
            'active learning and critical thinking. The question should lead toward understanding without ' .
            'giving away the answer. Use Socratic method principles.',
            [
                'type' => 'object',
                'properties' => [
                    'question_focus' => [
                        'type' => 'string',
                        'description' => 'What the question should focus on or lead the student to consider.',
                    ],
                    'question_type' => [
                        'type' => 'string',
                        'enum' => ['clarifying', 'probing', 'connecting', 'hypothetical'],
                        'description' => 'The type of guiding question to ask.',
                    ],
                    'builds_on' => [
                        'type' => 'string',
                        'description' => 'Optional: What prior knowledge or statement this question builds upon.',
                    ],
                ],
                'required' => ['question_focus'],
            ]
        );

        // Tool: give_example.
        $this->register_tool(
            'give_example',
            'local_student_support\agent\actions\give_example',
            'Provide an example to illustrate a concept. Use a DIFFERENT but SIMILAR example to what ' .
            'the student is working on. Show the process or reasoning, not just the result. ' .
            'NEVER solve the student\'s actual problem or provide examples too similar to their assignment.',
            [
                'type' => 'object',
                'properties' => [
                    'concept' => [
                        'type' => 'string',
                        'description' => 'The concept to illustrate with an example.',
                    ],
                    'example_type' => [
                        'type' => 'string',
                        'enum' => ['analogy', 'simplified', 'real_world', 'step_by_step'],
                        'description' => 'The type of example to provide.',
                    ],
                    'avoid_similarity_to' => [
                        'type' => 'string',
                        'description' => 'Description of what the example should NOT be too similar to (student\'s task).',
                    ],
                ],
                'required' => ['concept', 'example_type'],
            ]
        );

        // Tool: rephrase_instruction.
        $this->register_tool(
            'rephrase_instruction',
            'local_student_support\agent\actions\rephrase_instruction',
            'Rephrase or reformulate a previous explanation in different terms. Use this when the student ' .
            'is confused or needs clarification. Try simpler vocabulary, different analogies, or ' .
            'alternative perspectives. Do NOT simply repeat the same explanation.',
            [
                'type' => 'object',
                'properties' => [
                    'original_point' => [
                        'type' => 'string',
                        'description' => 'The point or concept that needs to be rephrased.',
                    ],
                    'approach' => [
                        'type' => 'string',
                        'enum' => ['simpler_language', 'different_angle', 'step_by_step', 'analogy'],
                        'description' => 'The approach to use for rephrasing.',
                    ],
                    'confusion_point' => [
                        'type' => 'string',
                        'description' => 'Optional: The specific point of confusion to address.',
                    ],
                ],
                'required' => ['original_point', 'approach'],
            ]
        );

        // Tool: respond_directly.
        // This is for when the LLM determines no specific action is needed.
        $this->register_tool(
            'respond_directly',
            null, // No action class - handled specially.
            'Respond directly to the student without invoking a specific pedagogical action. Use this for: ' .
            'greetings, simple acknowledgments, clarifying questions about what the student needs, ' .
            'or when redirecting inappropriate requests. Keep responses focused on learning support.',
            [
                'type' => 'object',
                'properties' => [
                    'response_type' => [
                        'type' => 'string',
                        'enum' => ['greeting', 'acknowledgment', 'clarification_needed', 'redirect', 'encouragement'],
                        'description' => 'The type of direct response.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'The message to send to the student.',
                    ],
                ],
                'required' => ['response_type', 'message'],
            ]
        );
    }

    /**
     * Register a tool.
     *
     * @param string $name Tool name (must match action name).
     * @param string|null $actionclass Full class name of the action (null for special tools).
     * @param string $description Tool description for the LLM.
     * @param array $parameters JSON Schema for parameters.
     * @return void
     */
    public function register_tool(string $name, ?string $actionclass, string $description, array $parameters): void {
        $this->tools[$name] = [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];

        if ($actionclass !== null) {
            $this->actionmap[$name] = $actionclass;
        }
    }

    /**
     * Get all registered tools in OpenAI format.
     *
     * @return array Array of tool definitions.
     */
    public function get_tools(): array {
        return array_values($this->tools);
    }

    /**
     * Get a specific tool definition.
     *
     * @param string $name Tool name.
     * @return array|null Tool definition or null if not found.
     */
    public function get_tool(string $name): ?array {
        return $this->tools[$name] ?? null;
    }

    /**
     * Check if a tool is registered.
     *
     * @param string $name Tool name.
     * @return bool True if registered.
     */
    public function has_tool(string $name): bool {
        return isset($this->tools[$name]);
    }

    /**
     * Get the action class for a tool.
     *
     * @param string $toolname Tool name.
     * @return string|null Action class name or null.
     */
    public function get_action_class(string $toolname): ?string {
        return $this->actionmap[$toolname] ?? null;
    }

    /**
     * Validate a tool call.
     *
     * @param string $toolname Tool name.
     * @param array $arguments Tool arguments.
     * @return array Validation result with 'valid' and 'errors'.
     */
    public function validate_tool_call(string $toolname, array $arguments): array {
        if (!$this->has_tool($toolname)) {
            return [
                'valid' => false,
                'errors' => ["Unknown tool: {$toolname}"],
            ];
        }

        $tool = $this->tools[$toolname];
        $schema = $tool['function']['parameters'];
        $errors = [];

        // Check required parameters.
        $required = $schema['required'] ?? [];
        foreach ($required as $param) {
            if (!isset($arguments[$param]) || $arguments[$param] === '') {
                $errors[] = "Missing required parameter: {$param}";
            }
        }

        // Validate enum values.
        $properties = $schema['properties'] ?? [];
        foreach ($arguments as $key => $value) {
            if (isset($properties[$key]['enum'])) {
                if (!in_array($value, $properties[$key]['enum'], true)) {
                    $errors[] = "Invalid value for {$key}: {$value}";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get tool names only.
     *
     * @return array List of tool names.
     */
    public function get_tool_names(): array {
        return array_keys($this->tools);
    }

    /**
     * Check if a tool has an associated action class.
     *
     * @param string $toolname Tool name.
     * @return bool True if tool has an action class.
     */
    public function tool_has_action(string $toolname): bool {
        return isset($this->actionmap[$toolname]);
    }
}
