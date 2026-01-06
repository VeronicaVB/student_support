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
 * Ask guiding question action.
 *
 * Uses Socratic questioning to guide students toward understanding.
 * Helps students discover answers through their own reasoning.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ask_guiding_question extends base_action {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string {
        return 'ask_guiding_question';
    }

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Ask a guiding question to help the student think through the problem';
    }

    /**
     * Check if this is a guidance action.
     *
     * @return bool True - guiding questions count toward guidance attempts.
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
Your task is to ASK A GUIDING QUESTION that helps the student think.

Guidelines for guiding questions:
1. Ask ONE focused question at a time
2. The question should lead toward understanding, not give away answers
3. Build on what the student already knows
4. Make the question specific to their situation
5. Encourage them to think about the "why" or "how"
6. Use questions that can't be answered with just yes/no

Types of effective guiding questions:
- "What do you think would happen if...?"
- "Can you explain what [concept] means in your own words?"
- "What's the first step you would take to...?"
- "How does this relate to [something they know]?"
- "What information do you already have about this?"

DO NOT:
- Ask leading questions that give away the answer
- Ask multiple questions at once
- Ask rhetorical questions
- Provide the answer immediately after asking

Wait for their response before providing more guidance.
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
        $attempts = $memory->get_guidance_attempts();
        $maxattempts = $config->get_max_attempts();

        // Check if we should escalate.
        $escalationnotice = '';
        if ($analysis['should_escalate'] ?? false) {
            $escalationnotice = <<<NOTICE

## IMPORTANT: ESCALATION NEEDED
The student has received {$attempts} guidance attempts and may benefit from
speaking with their teacher. Include a gentle suggestion that they might
want to discuss this with their teacher for additional help.
NOTICE;
        }

        // Check if this is responding to a request for direct answers.
        $redirectnotice = '';
        if (($analysis['intent']['type'] ?? '') === 'request_answer') {
            $redirectnotice = <<<NOTICE

## IMPORTANT: REDIRECT NEEDED
The student is asking for a direct answer. Instead of providing it:
1. Acknowledge their desire to know the answer
2. Explain that discovering it themselves will help them learn better
3. Ask a guiding question that helps them work toward the answer
NOTICE;
        }

        $prompt = <<<PROMPT
{$systemprompt}

## Current Action: ASK GUIDING QUESTION
{$actioninstruction}
{$escalationnotice}
{$redirectnotice}

## Context
Topic: {$topic}
Guidance attempt: {$attempts} of {$maxattempts}

## Conversation History
{$history}

## Student's Current Message
{$usermessage}

## Your Response
Ask ONE thoughtful guiding question that helps the student think through this:
PROMPT;

        return $prompt;
    }
}
