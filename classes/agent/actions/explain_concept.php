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
 * Explain concept action - CONTROLLED RENDERER.
 *
 * This action generates a brief, focused explanation of ONE concept.
 * It is NOT an agent - it does not reason or decide strategy.
 *
 * QUESTION BEHAVIOR (PHASE-DEPENDENT):
 * - no_questions modifier: NO question (for NO_MENTAL_MODEL phase)
 * - optional_question modifier: Question is optional (for PARTIAL_MENTAL_MODEL)
 * - Default (no modifier): Question is mandatory (for FUNCTIONAL_MENTAL_MODEL)
 *
 * Output constraints:
 * - Maximum 3 short paragraphs
 * - ONE foundational idea only
 * - Question based on phase (see above)
 * - No complete topic coverage
 * - No textbook-style explanations
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
        return 'Explain a single foundational concept briefly';
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
     * Build the ISOLATED user prompt for this action.
     *
     * CONTEXT ISOLATION: Only includes:
     * - The concept to explain (from memory, NOT extracted from message)
     * - The student's current message
     * - Recent conversation context (last 2-3 exchanges)
     * - Strict output constraints
     * - Modifiers (for conditional questions based on phase)
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

        // Get recent conversation context (last 2 exchanges = 4 messages).
        $recentcontext = $this->get_recent_conversation_context($memory, 4);

        // Get modifiers from action policy (determines question behavior).
        $modifiers = $context['modifiers'] ?? [];

        return $this->build_focused_instruction_with_context($topic, $studentmessage, $recentcontext, $modifiers);
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

        // Get last N messages (excluding the current one which is already in user_message).
        $recentmessages = array_slice($messages, -($maxmessages + 1), -1);

        if (empty($recentmessages)) {
            return '';
        }

        $contextlines = [];
        foreach ($recentmessages as $msg) {
            $role = ($msg['role'] === 'user') ? 'Student' : 'Tutor';
            $content = trim($msg['content']);
            // Truncate long messages.
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
     * Handles CONDITIONAL QUESTIONS based on cognitive phase modifiers:
     * - no_questions: NO question (NO_MENTAL_MODEL phase)
     * - optional_question: Question is optional (PARTIAL_MENTAL_MODEL phase)
     * - Default: Question is mandatory (FUNCTIONAL_MENTAL_MODEL phase)
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
        // Minimal context - just last exchange.
        $contextblock = '';
        if (!empty($recentcontext)) {
            $lines = explode("\n", $recentcontext);
            $lastlines = array_slice($lines, -2);
            $contextblock = "Previous exchange: " . implode(" | ", $lastlines);
        }

        // Determine question behavior based on modifiers.
        $noquestions = !empty($modifiers['no_questions']);
        $optionalquestion = !empty($modifiers['optional_question']);
        $allowanalogy = empty($modifiers['no_analogies']);

        // Build question rule based on phase.
        if ($noquestions) {
            $questionrule = "- Do NOT ask any questions - just explain";
            $sentencecount = "3";
            $exampleformat = '"I understand [topic] can be tricky. [Simple explanation]. [One more clarifying sentence]."';
        } elseif ($optionalquestion) {
            $questionrule = "- You MAY optionally end with a brief question, but it is not required";
            $sentencecount = "3-4";
            $exampleformat = '"I understand [topic] can be tricky. [Simple explanation]. [One more clarifying sentence]. (Optional: brief question)"';
        } else {
            $questionrule = "- Final sentence: Ask ONE question to check understanding";
            $sentencecount = "4";
            $exampleformat = '"I understand [topic] can be tricky. Think of it like [simple analogy]. [One more clarifying sentence]. Does that help clarify [specific aspect]?"';
        }

        // Build analogy rule.
        $analogyrule = $allowanalogy
            ? "- Sentences 2-3: Explain ONE aspect of {$concept} simply, use an analogy if helpful"
            : "- Sentences 2-3: Explain ONE aspect of {$concept} simply and directly";

        return <<<INSTRUCTION
Topic: {$concept}
Student said: "{$studentmessage}"
{$contextblock}

TASK: Explain ONE small part of {$concept} simply.

RULES:
- Maximum {$sentencecount} sentences total
- Sentence 1: Acknowledge what student said (max 10 words)
{$analogyrule}
{$questionrule}
- Stay on {$concept} - do NOT change topics
- If student said "everything" or similar, they mean about {$concept}

OUTPUT FORMAT EXAMPLE:
{$exampleformat}

Write your response now (max {$sentencecount} sentences):
INSTRUCTION;
    }

    /**
     * Build focused instruction for explaining a concept.
     *
     * Legacy method - used when no context is available.
     *
     * @param string $concept The concept to explain.
     * @param string $studentmessage The student's original message.
     * @return string Focused instruction.
     */
    protected function build_focused_instruction(string $concept, string $studentmessage): string {
        return <<<INSTRUCTION
TASK: Explain ONE foundational idea about "{$concept}"

STUDENT MESSAGE:
"{$studentmessage}"

MANDATORY PEDAGOGICAL RULE:
- The student has already expressed an idea or intuition.
- You MUST explicitly acknowledge or build on the student's idea before introducing new explanations.
- Do NOT ignore, overwrite, or restart from a generic definition.

STRICT OUTPUT FORMAT:
1. First paragraph:
   - Briefly acknowledge or rephrase the student's idea
   - Connect it to the core concept in simple terms
   - 2–3 sentences maximum

2. Second paragraph:
   - Give ONE brief analogy or example that extends the student's idea
   - 2–3 sentences maximum

3. Third paragraph:
   - Ask EXACTLY ONE guiding question to check understanding

FORBIDDEN:
- Do NOT explain the full topic
- Do NOT introduce multiple concepts
- Do NOT use numbered or bulleted lists
- Do NOT provide final answers or solutions
- Do NOT exceed 3 short paragraphs

STOP after the question. Do not continue explaining.
INSTRUCTION;
    }
}
