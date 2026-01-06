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
 * Rephrase instruction action.
 *
 * Rephrases or reformulates explanations when the student
 * needs a different perspective or clearer language.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rephrase_instruction extends base_action {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string {
        return 'rephrase_instruction';
    }

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Rephrase or reformulate a previous explanation in different terms';
    }

    /**
     * Check if this is a guidance action.
     *
     * @return bool True - rephrasing counts toward guidance attempts.
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
Your task is to REPHRASE the previous explanation in different words.

Guidelines for rephrasing:
1. Use simpler vocabulary if possible
2. Try a different angle or perspective
3. Use shorter sentences
4. Focus on the core idea without extra details
5. Consider using a metaphor or comparison
6. Address any specific point of confusion mentioned

The student may be confused by:
- Technical terminology
- The level of abstraction
- Missing prerequisite knowledge
- The structure of the explanation

DO NOT:
- Simply repeat the same explanation
- Add more complexity
- Provide direct answers
- Give up on helping them understand

Ask if this new explanation helps clarify things.
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
        $attempts = $memory->get_guidance_attempts();

        $prompt = <<<PROMPT
{$systemprompt}

## Current Action: REPHRASE INSTRUCTION
{$actioninstruction}

## Context
This is guidance attempt #{$attempts} on this topic.
The student has indicated they need clarification or a different explanation.

## Conversation History
{$history}

## Student's Current Message
{$usermessage}

## Your Response
Provide the same concept explained differently, in simpler or alternative terms:
PROMPT;

        return $prompt;
    }
}
