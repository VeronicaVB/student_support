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
 * Rephrase instruction action - CONTROLLED RENDERER.
 *
 * Rephrases a concept using different words, analogy, or perspective.
 * This is NOT an agent - it does not reason or decide strategy.
 *
 * Output constraints:
 * - Maximum 2 short paragraphs
 * - Must use DIFFERENT wording from previous explanation
 * - Simpler vocabulary
 * - Ends with ONE question
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
        return 'Rephrase using simpler or different words';
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
     * Build the ISOLATED user prompt for this action.
     *
     * CONTEXT ISOLATION: Only includes:
     * - The topic (from memory for continuity)
     * - The student's current message
     * - Recent conversation context
     * - The LAST assistant message (for context on what to rephrase)
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

        // Get modifiers from action policy.
        $modifiers = $context['modifiers'] ?? [];

        // Get ONLY the last assistant message for context on what to rephrase.
        $lastresponse = $this->get_last_assistant_message($context);
        $previouscontext = '';
        if ($lastresponse) {
            // Truncate to keep context minimal.
            $truncated = mb_substr($lastresponse, 0, 200);
            if (mb_strlen($lastresponse) > 200) {
                $truncated .= '...';
            }
            $previouscontext = $truncated;
        }

        return $this->build_focused_instruction_with_context(
            $topic,
            $studentmessage,
            $recentcontext,
            $previouscontext,
            $modifiers
        );
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
     * @param string $previousresponse Previous assistant response to rephrase.
     * @param array $modifiers Action modifiers from policy.
     * @return string Focused instruction.
     */
    private function build_focused_instruction_with_context(
        string $concept,
        string $studentmessage,
        string $recentcontext,
        string $previousresponse,
        array $modifiers = []
    ): string {
        // Build minimal context.
        $contextblock = '';
        if (!empty($previousresponse)) {
            $truncated = mb_substr($previousresponse, 0, 100);
            $contextblock = "Your previous explanation: \"{$truncated}...\"";
        }

        // Handle empathy modifier.
        $empathynote = '';
        if (!empty($modifiers['empathetic']) || !empty($modifiers['acknowledge_frustration'])) {
            $empathynote = "Note: Student seems frustrated. Be brief and encouraging.";
        }

        // Handle pushback/correction - student is correcting the tutor.
        $ispushback = $this->is_pushback_message($studentmessage);
        if ($ispushback) {
            return $this->build_pushback_response_instruction($concept, $studentmessage);
        }

        return <<<INSTRUCTION
Topic: {$concept}
Student said: "{$studentmessage}"
{$contextblock}
{$empathynote}

TASK: Re-explain {$concept} using DIFFERENT, SIMPLER words.

RULES:
- Maximum 3 sentences total
- Sentence 1: Brief acknowledgment (max 8 words)
- Sentence 2-3: Simple re-explanation of {$concept} using an analogy or simpler words
- End with ONE short question to check understanding
- Stay on topic: {$concept}
- Do NOT explain new concepts

OUTPUT FORMAT EXAMPLE:
"Let me try explaining differently. [Simple analogy or rephrasing]. Does that make more sense?"

Write your response now (max 4 sentences):
INSTRUCTION;
    }

    /**
     * Check if the message is a pushback/correction from the student.
     *
     * @param string $message The student message.
     * @return bool True if this is a pushback message.
     */
    private function is_pushback_message(string $message): bool {
        $lowermessage = strtolower($message);
        $pushbackpatterns = [
            '/you\s+(just\s+)?(came\s+up\s+with|said|mentioned|brought\s+up|made\s+up)/',
            '/that\'?s\s+(your|what\s+you)/',
            '/that\'?s\s+not\s+what\s+i\s+(asked|said|meant)/',
            '/i\s+didn\'?t\s+(say|ask|mean)/',
            '/you\'?re\s+(the\s+one|explaining)/',
            '/i\s+never\s+(said|asked|mentioned)/',
            '/that\s+was\s+your\s+(idea|example|analogy)/',
            '/why\s+are\s+you\s+(asking|saying|talking\s+about)/',
        ];

        foreach ($pushbackpatterns as $pattern) {
            if (preg_match($pattern, $lowermessage)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build instruction for responding to student pushback/correction.
     *
     * When student corrects the tutor, we need to acknowledge the correction
     * and refocus on what they actually need help with.
     *
     * @param string $concept The concept being discussed.
     * @param string $studentmessage The student's correction message.
     * @return string Focused instruction.
     */
    private function build_pushback_response_instruction(string $concept, string $studentmessage): string {
        return <<<INSTRUCTION
Topic: {$concept}
Student said: "{$studentmessage}"

SITUATION: The student is correcting you or pointing out that something you said was YOUR idea, not theirs.

TASK: Acknowledge the correction gracefully and refocus on helping them.

RULES:
- Maximum 2 sentences total
- Sentence 1: Briefly acknowledge their point (e.g., "You're right, that was my analogy.")
- Sentence 2: Ask what specifically they need help with about {$concept}
- Do NOT repeat the analogy or idea they objected to
- Do NOT be defensive
- Be humble and redirect to their needs

OUTPUT FORMAT EXAMPLE:
"You're right, I introduced that comparison. What part of {$concept} would you like me to focus on?"

Write your response now (max 2 sentences):
INSTRUCTION;
    }

    /**
     * Build focused instruction for rephrasing.
     *
     * Legacy method - used when no context is available.
     *
     * @param string $concept The concept/topic.
     * @param string $studentmessage The student's original message.
     * @return string Focused instruction.
     */
    protected function build_focused_instruction(string $concept, string $studentmessage): string {
        return <<<INSTRUCTION
TASK: Rephrase the explanation of "{$concept}" using DIFFERENT words

STUDENT SAID: "{$studentmessage}"

STRICT OUTPUT FORMAT:
1. First paragraph: Re-explain the core idea using simpler vocabulary OR a different analogy (2-3 sentences max)
2. Second paragraph: ONE question to check if this version is clearer

REPHRASING STRATEGIES (pick ONE):
- Use simpler everyday words
- Try a different analogy or comparison
- Focus on just ONE aspect they seem confused about
- Use shorter sentences

FORBIDDEN:
- Do NOT repeat the same explanation
- Do NOT add more complexity
- Do NOT explain additional concepts
- Do NOT use technical jargon
- Do NOT write more than 2 paragraphs

Generate ONLY the rephrased explanation and ONE question. Stop immediately after.
INSTRUCTION;
    }
}
