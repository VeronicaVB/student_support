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
 * Give example action.
 *
 * Provides examples to illustrate concepts.
 * Uses partial or analogous examples, not complete solutions.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class give_example extends base_action {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string {
        return 'give_example';
    }

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Provide an example to illustrate a concept';
    }

    /**
     * Check if this is a guidance action.
     *
     * @return bool True - examples count toward guidance attempts.
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
Your task is to GIVE AN EXAMPLE that illustrates the concept.

Guidelines for examples:
1. Use a DIFFERENT but SIMILAR example to what the student is working on
2. Make it relatable to the student's grade level and experience
3. Show the process or reasoning, not just the result
4. Keep the example simple enough to understand quickly
5. Connect the example back to their original question
6. Consider using real-world analogies when appropriate

CRITICAL RULES:
- NEVER solve the student's actual problem
- NEVER provide an example that is too similar to their assignment
- If they're working on math problem X, show a different problem Y
- If they're writing an essay on topic A, illustrate with topic B
- The example should teach the METHOD, not give away the ANSWER

Example types to consider:
- Simpler version of the same concept
- Real-world application
- Analogy from everyday life
- Step-by-step walkthrough of a similar (not same) problem

After the example, ask if they can see how to apply this to their situation.
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
        $gradelevel = $config->get_grade_level() ?: 'appropriate';
        $subjectarea = $config->get_subject_area() ?: 'general';

        $prompt = <<<PROMPT
{$systemprompt}

## Current Action: GIVE EXAMPLE
{$actioninstruction}

## Context
Topic: {$topic}
Subject Area: {$subjectarea}
Grade Level: {$gradelevel}

## Conversation History
{$history}

## Student's Current Message
{$usermessage}

## Your Response
Provide a helpful example that illustrates the concept WITHOUT solving their specific problem:
PROMPT;

        return $prompt;
    }
}
