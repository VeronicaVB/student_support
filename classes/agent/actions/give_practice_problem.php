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
 * Give practice problem action - CONTROLLED RENDERER.
 *
 * Generates ONE practice problem for the student to solve.
 * This is different from give_example which shows a worked example.
 * This action gives a problem for the student to attempt themselves.
 *
 * Output constraints:
 * - ONE simple problem to solve
 * - Related to the current topic
 * - Appropriate difficulty level
 * - Clear instructions
 * - Encouragement to try
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class give_practice_problem extends base_action {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string {
        return 'give_practice_problem';
    }

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Provide ONE practice problem for the student to solve';
    }

    /**
     * Check if this is a guidance action.
     *
     * @return bool True - practice problems count toward guidance attempts.
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

        return $this->build_focused_instruction_with_context($topic, $studentmessage, $recentcontext);
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
     * @return string Focused instruction.
     */
    private function build_focused_instruction_with_context(
        string $concept,
        string $studentmessage,
        string $recentcontext
    ): string {
        return <<<INSTRUCTION
Topic: {$concept}
Student said: "{$studentmessage}"

TASK: Give ONE practice problem about {$concept} for the student to solve.

RULES:
- Maximum 3 sentences total
- Sentence 1: Brief encouragement (max 8 words)
- Sentence 2: State the problem clearly with simple numbers
- Sentence 3: Invite them to try it
- Do NOT give the answer
- Do NOT give hints
- Problem must be about {$concept}

OUTPUT FORMAT EXAMPLE:
"Great, let's try one! What is 3 × 4? Give it a try and tell me what you get."

Write your response now (max 3 sentences):
INSTRUCTION;
    }

    /**
     * Build focused instruction for giving a practice problem.
     *
     * Legacy method - used when no context is available.
     *
     * @param string $concept The concept/topic.
     * @param string $studentmessage The student's original message.
     * @return string Focused instruction.
     */
    protected function build_focused_instruction(string $concept, string $studentmessage): string {
        return <<<INSTRUCTION
Topic: {$concept}
Student said: "{$studentmessage}"

TASK: Give ONE practice problem about {$concept}.

RULES:
- Max 3 sentences
- Sentence 1: Brief encouragement
- Sentence 2: State the problem with simple numbers
- Sentence 3: Invite them to try
- Do NOT give the answer

Write your response (max 3 sentences):
INSTRUCTION;
    }
}
