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

defined('MOODLE_INTERNAL') || die();

/**
 * Base class for agent actions.
 *
 * Provides common functionality for all actions.
 * Subclasses must implement the abstract methods.
 *
 * NOTE: In this skeleton implementation, the execute() method returns
 * a placeholder response. The actual AI integration via Neuron will be
 * implemented in a future phase.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_action implements action_interface {

    /**
     * Execute the action.
     *
     * NOTE: This is a skeleton implementation. The actual execution
     * will use Neuron AI to send the prompt and receive a response.
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
        // Build the prompt.
        $prompt = $this->build_prompt($context, $analysis, $config, $memory);

        // TODO: In future implementation, this will:
        // 1. Use Neuron AI to send the prompt to the configured AI provider
        // 2. Parse the response
        // 3. Return the formatted response
        //
        // For now, return a placeholder indicating the action was selected.

        return [
            'success' => true,
            'message' => $this->get_placeholder_response($context, $analysis, $config),
            'metadata' => [
                'action' => $this->get_name(),
                'prompt_length' => strlen($prompt),
                'intent' => $analysis['intent']['type'] ?? 'unknown',
                'placeholder' => true, // Indicates this is not a real AI response.
            ],
        ];
    }

    /**
     * Get a placeholder response for skeleton implementation.
     *
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @param agent_config $config Agent configuration.
     * @return string Placeholder response.
     */
    protected function get_placeholder_response(
        array $context,
        array $analysis,
        agent_config $config
    ): string {
        // This will be replaced by actual AI response in future implementation.
        return sprintf(
            '[%s action would execute here with %s approach for grade level: %s]',
            $this->get_name(),
            $config->get_pedagogical_approach(),
            $config->get_grade_level() ?: 'not set'
        );
    }

    /**
     * Build the system prompt with agent context.
     *
     * @param agent_config $config Agent configuration.
     * @return string System prompt.
     */
    protected function build_system_prompt(agent_config $config): string {
        $context = $config->build_agent_context();
        $goals = $config->get_agent_goals();
        $constraints = $config->get_agent_constraints();

        $systemprompt = "You are a Student Support Agent, designed to help students understand their learning materials.\n\n";

        // Add goals.
        $systemprompt .= "## Your Goals\n";
        foreach ($goals as $key => $goal) {
            $systemprompt .= "- {$goal}\n";
        }
        $systemprompt .= "\n";

        // Add constraints.
        $systemprompt .= "## Absolute Constraints (NEVER violate)\n";
        foreach ($constraints as $key => $constraint) {
            $systemprompt .= "- {$constraint}\n";
        }
        $systemprompt .= "\n";

        // Add context.
        $systemprompt .= "## Current Context\n";
        $systemprompt .= "- Curriculum: {$context['curriculum']['name']} ({$context['curriculum']['year']})\n";
        $systemprompt .= "- Student Grade Level: {$context['student']['grade_level']}\n";
        $systemprompt .= "- Subject Area: {$context['course']['subject_area']}\n";
        $systemprompt .= "- Response Language: {$context['behaviour']['response_language']}\n";
        $systemprompt .= "- Pedagogical Approach: {$context['behaviour']['pedagogical_approach']}\n";
        $systemprompt .= "\n";

        // Add response guidelines.
        $systemprompt .= "## Response Guidelines\n";
        $systemprompt .= "- Use verbs: explain, guide, reformulate, ask\n";
        $systemprompt .= "- Structure responses in conceptual steps\n";
        $systemprompt .= "- Avoid \"final answer\" formats\n";
        $systemprompt .= "- Verify understanding before closing interactions\n";
        $systemprompt .= "- If student requests direct answers, redirect pedagogically\n";
        $systemprompt .= "- Maintain a professional, clear, respectful teacher tone\n";

        return $systemprompt;
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
     * Subclasses should override this to provide specific instructions.
     *
     * @return string Action-specific instruction.
     */
    abstract protected function get_action_instruction(): string;
}
