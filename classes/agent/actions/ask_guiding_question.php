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
 * Ask guiding question action - CONTROLLED RENDERER.
 *
 * Generates exactly ONE Socratic question to guide student thinking.
 * This is NOT an agent - it does not reason or decide strategy.
 *
 * Output constraints:
 * - Maximum 2 short sentences of context
 * - Exactly ONE guiding question
 * - No explanations
 * - No answers disguised as hints
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
        return 'Ask exactly ONE guiding question';
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
     * Build the ISOLATED user prompt for this action.
     *
     * CONTEXT ISOLATION: Only includes:
     * - The topic (from memory for continuity)
     * - The student's current message
     * - Recent conversation context
     * - Strict output constraints
     *
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return string Focused user prompt.
     */
    protected function build_isolated_user_prompt(
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): string {
        $studentmessage = $context['user_message'] ?? '';

        // IMPORTANT: Use get_conversation_topic() to maintain conversation continuity.
        $topic = $this->get_conversation_topic($context, $memory);

        // Get recent conversation context.
        $recentcontext = $this->get_recent_conversation_context($memory, 4);

        // Check modifiers from action policy.
        $modifiers = $context['modifiers'] ?? [];

        // Check if escalation is needed.
        $escalationhint = '';
        if ($memory->get_guidance_attempts() >= $config->get_max_attempts()) {
            $escalationhint = "\nNOTE: After your question, briefly suggest asking their teacher if they remain stuck.";
        }

        return $this->build_focused_instruction_with_context(
            $topic,
            $studentmessage,
            $recentcontext,
            $modifiers
        ) . $escalationhint;
    }

    /**
     * Get recent conversation context for continuity.
     *
     * @param agent_memory $memory Agent memory.
     * @param int $maxmessages Maximum messages to include.
     * @return string Formatted recent context.
     */
    private function get_recent_conversation_context(agent_memory $memory, int $maxmessages = 4): string {
        $messages = $memory->get_messages();

        if (empty($messages)) {
            return '';
        }

        // Get last N messages (excluding the current one).
        $recentmessages = array_slice($messages, -($maxmessages + 1), -1);

        if (empty($recentmessages)) {
            return '';
        }

        $contextlines = [];
        foreach ($recentmessages as $msg) {
            $role = ($msg['role'] === 'user') ? 'Student' : 'Tutor';
            $content = trim($msg['content']);
            if (mb_strlen($content) > 200) {
                $content = mb_substr($content, 0, 200) . '...';
            }
            $contextlines[] = "{$role}: {$content}";
        }

        return implode("\n", $contextlines);
    }

    /**
     * Build focused instruction with conversation context.
     *
     * @param string $concept The concept being discussed.
     * @param string $studentmessage The student's current message.
     * @param string $recentcontext Recent conversation context.
     * @param array $modifiers Action modifiers from policy.
     * @return string Focused instruction.
     */
    private function build_focused_instruction_with_context(
        string $concept,
        string $studentmessage,
        string $recentcontext,
        array $modifiers = []
    ): string {
        // Build minimal context - only last 2 messages.
        $contextblock = '';
        if (!empty($recentcontext)) {
            $lines = explode("\n", $recentcontext);
            $lastlines = array_slice($lines, -2);
            $contextblock = "Context: " . implode(" | ", $lastlines);
        }

        // Determine question style.
        $style = 'open-ended';
        if (!empty($modifiers['micro']) || !empty($modifiers['closed_question'])) {
            $style = 'yes/no';
        }

        return <<<INSTRUCTION
Topic: {$concept}
Student said: "{$studentmessage}"
{$contextblock}

TASK: Ask ONE {$style} question about {$concept}.

RULES:
- Maximum 2 sentences total
- First sentence: brief acknowledgment (optional, max 10 words)
- Second sentence: ONE question about {$concept}
- The question must help the student think about {$concept}
- Do NOT explain anything
- Do NOT change topics

OUTPUT FORMAT EXAMPLE:
"I see you're working on [topic]. What do you think is the first step to [specific aspect]?"

Write your response now (max 2 sentences):
INSTRUCTION;
    }

    /**
     * Build focused instruction for asking a guiding question.
     *
     * Legacy method - used when no context is available.
     *
     * @param string $concept The concept/topic.
     * @param string $studentmessage The student's original message.
     * @return string Focused instruction.
     */
    protected function build_focused_instruction(string $concept, string $studentmessage): string {
        return <<<INSTRUCTION
TASK: Generate ONE Socratic guiding question about "{$concept}"

STUDENT ASKED: "{$studentmessage}"

STRICT OUTPUT FORMAT:
1. Optional: ONE short sentence acknowledging their question (max 15 words)
2. EXACTLY ONE open-ended question that guides their thinking

FORBIDDEN:
- Do NOT ask multiple questions
- Do NOT explain concepts
- Do NOT provide hints that reveal the answer
- Do NOT ask yes/no questions
- Do NOT write more than 2 sentences total

Good question patterns:
- "What do you think happens when...?"
- "How would you describe...in your own words?"
- "What's the relationship between X and Y?"
- "Why do you think that...?"

Generate ONLY the brief acknowledgment (optional) and ONE question. Stop immediately after.
INSTRUCTION;
    }
}
