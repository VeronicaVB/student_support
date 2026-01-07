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

namespace local_student_support\agent\actions;

use local_student_support\agent\agent_config;
use local_student_support\agent\agent_memory;
use local_student_support\ai\openai_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Base class for agent actions.
 *
 * Provides common functionality for all actions including:
 * - Prompt building
 * - LLM execution via OpenAI client
 * - Response handling
 *
 * Subclasses must implement the abstract methods to define
 * action-specific behavior and prompts.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_action implements action_interface {

    /**
     * Execute the action using the LLM.
     *
     * This method:
     * 1. Builds the action-specific prompt
     * 2. Sends it to the OpenAI API
     * 3. Returns the formatted response
     *
     * @param array $context Gathered context from GAME loop.
     * @param array $analysis Analysis results from GAME loop.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return array Response with 'success', 'message', and 'metadata'.
     */
    public function execute(
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): array {
        // Build the prompt for this action.
        $prompt = $this->build_prompt($context, $analysis, $config, $memory);

        // Create OpenAI client.
        $client = new openai_client();

        // Check if client is configured.
        if (!$client->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('error:notconfigured', 'local_student_support'),
                'metadata' => [
                    'action' => $this->get_name(),
                    'error' => 'api_not_configured',
                ],
            ];
        }

        // Build messages for the API.
        // For action execution, we use the action prompt as a user message
        // with the system prompt providing overall context.
        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        // Build system prompt.
        $systemprompt = $this->build_action_system_prompt($config, $analysis);

        // Call the LLM (no tools - we want a direct text response).
        $response = $client->ask($systemprompt, $messages, []);

        // Handle the response.
        return $this->handle_llm_response($response, $config);
    }

    /**
     * Execute the action with tool call arguments.
     *
     * This variant is called when the LLM has already selected this action
     * via function calling and provided arguments.
     *
     * @param array $arguments Arguments from the tool call.
     * @param array $context Gathered context from GAME loop.
     * @param array $analysis Analysis results from GAME loop.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return array Response with 'success', 'message', and 'metadata'.
     */
    public function execute_with_arguments(
        array $arguments,
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): array {
        // Build prompt incorporating the tool arguments.
        $prompt = $this->build_prompt_with_arguments($arguments, $context, $analysis, $config, $memory);

        // Create OpenAI client.
        $client = new openai_client();

        if (!$client->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('error:notconfigured', 'local_student_support'),
                'metadata' => [
                    'action' => $this->get_name(),
                    'error' => 'api_not_configured',
                ],
            ];
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $systemprompt = $this->build_action_system_prompt($config, $analysis);
        $response = $client->ask($systemprompt, $messages, []);

        return $this->handle_llm_response($response, $config);
    }

    /**
     * Handle the LLM response.
     *
     * @param array $response Response from OpenAI client.
     * @param agent_config $config Agent configuration.
     * @return array Formatted response for the agent.
     */
    protected function handle_llm_response(array $response, agent_config $config): array {
        if ($response['type'] === openai_client::RESPONSE_ERROR) {
            return [
                'success' => false,
                'message' => get_string('error:apierror', 'local_student_support'),
                'metadata' => [
                    'action' => $this->get_name(),
                    'error' => $response['error'] ?? 'unknown_error',
                    'api_metadata' => $response['metadata'] ?? [],
                ],
            ];
        }

        if ($response['type'] === openai_client::RESPONSE_TEXT) {
            return [
                'success' => true,
                'message' => $response['content'],
                'metadata' => [
                    'action' => $this->get_name(),
                    'model' => $response['metadata']['model'] ?? $config->get_model(),
                    'usage' => $response['metadata']['usage'] ?? null,
                    'duration_ms' => $response['metadata']['duration_ms'] ?? null,
                ],
            ];
        }

        // Unexpected response type.
        return [
            'success' => false,
            'message' => get_string('error:apierror', 'local_student_support'),
            'metadata' => [
                'action' => $this->get_name(),
                'error' => 'unexpected_response_type',
                'response_type' => $response['type'],
            ],
        ];
    }

    /**
     * Build the system prompt for action execution.
     *
     * @param agent_config $config Agent configuration.
     * @param array $analysis Analysis results.
     * @return string System prompt.
     */
    protected function build_action_system_prompt(agent_config $config, array $analysis): string {
        $agentcontext = $config->build_agent_context();

        $prompt = <<<PROMPT
You are a Student Support Agent executing a specific pedagogical action.

## Role and Context
You are operating in a formal educational environment.
Your role is to support student learning, not to complete tasks or provide final answers.
You assist students in understanding concepts, instructions, and expectations.

## Current Action: {$this->get_name()}

## Strict Prohibitions (NEVER violate these)
- NEVER provide final answers or complete solutions
- NEVER write essays, code, or responses that can be submitted as student work
- NEVER solve evaluable exercises
- NEVER evaluate, grade, or judge academic performance
- NEVER introduce content outside the student's educational level
- NEVER adopt a casual or peer-like persona

## Current Context
- Curriculum: {$agentcontext['curriculum']['name']} ({$agentcontext['curriculum']['year']})
- Student Grade Level: {$agentcontext['student']['grade_level']}
- Subject Area: {$agentcontext['course']['subject_area']}
- Response Language: {$agentcontext['behaviour']['response_language']}
- Pedagogical Approach: {$agentcontext['behaviour']['pedagogical_approach']}

## Response Guidelines
- Be professional, respectful, and encouraging
- Structure responses in clear, digestible steps
- Adapt language complexity to the student's grade level
- End with a way to verify understanding or invite further questions
- Keep responses focused and concise
PROMPT;

        return $prompt;
    }

    /**
     * Build prompt incorporating tool arguments.
     *
     * Subclasses can override this to customize how arguments are used.
     *
     * @param array $arguments Tool call arguments.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return string The prompt.
     */
    protected function build_prompt_with_arguments(
        array $arguments,
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): string {
        // Default implementation: include arguments in the base prompt.
        $baseprompt = $this->build_prompt($context, $analysis, $config, $memory);

        if (empty($arguments)) {
            return $baseprompt;
        }

        $argstext = "\n\n## Action Parameters\n";
        foreach ($arguments as $key => $value) {
            $argstext .= "- {$key}: {$value}\n";
        }

        return $baseprompt . $argstext;
    }

    /**
     * Format conversation history for the prompt.
     *
     * @param array $messages Conversation messages.
     * @return string Formatted history.
     */
    protected function format_conversation_history(array $messages): string {
        if (empty($messages)) {
            return "No previous messages.";
        }

        $formatted = "";
        foreach ($messages as $msg) {
            $role = ucfirst($msg['role']);
            $formatted .= "{$role}: {$msg['content']}\n\n";
        }

        return trim($formatted);
    }

    /**
     * Get the action-specific instruction.
     *
     * Subclasses must implement this to provide specific instructions
     * for the LLM when executing this action.
     *
     * @return string Action-specific instruction.
     */
    abstract protected function get_action_instruction(): string;
}
