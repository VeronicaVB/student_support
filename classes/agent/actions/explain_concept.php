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
 * Explain concept action.
 *
 * Provides conceptual explanations to help students understand topics.
 * Focuses on breaking down complex ideas into understandable parts.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explain_concept extends base_action {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string {
        return 'explain_concept';
    }

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Explain a concept or topic to help the student understand';
    }

    /**
     * Check if this is a guidance action.
     *
     * @return bool True - explanations count toward guidance attempts.
     */
    public function is_guidance_action(): bool {
        return true;
    }

    /**
     * Get the action-specific instruction.
     *
     * @return string Action-specific instruction.
     */
    protected function get_action_instruction(): string {
        return <<<INSTRUCTION
Your task is to EXPLAIN the concept the student is asking about.

Guidelines for explanation:
1. Break down the concept into smaller, digestible parts
2. Start with the foundational idea before moving to details
3. Use language appropriate for the student's grade level
4. Connect to concepts they likely already know
5. Use analogies when helpful
6. Structure your explanation in clear steps

DO NOT:
- Provide complete solutions to problems
- Give direct answers to assessment questions
- Overwhelm with too much information at once
- Use jargon without explaining it

End your explanation by checking if the student understood the main idea.
INSTRUCTION;
    }

    /**
     * Build the prompt for this action.
     *
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return string The prompt to send to the AI.
     */
    public function build_prompt(
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): string {
        $systemprompt = $this->build_system_prompt($config);
        $actioninstruction = $this->get_action_instruction();
        $history = $this->format_conversation_history($context['conversation_history']);
        $usermessage = $context['user_message'];
        $topic = $memory->get_current_topic() ?? 'the topic at hand';

        $prompt = <<<PROMPT
{$systemprompt}

## Current Action: EXPLAIN CONCEPT
{$actioninstruction}

## Topic Context
The student appears to be asking about: {$topic}

## Conversation History
{$history}

## Student's Current Message
{$usermessage}

## Your Response
Provide a clear, step-by-step explanation appropriate for this student's level:
PROMPT;

        return $prompt;
    }
}
