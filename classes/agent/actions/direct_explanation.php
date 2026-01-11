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
 * Direct explanation action - FOR NO_MENTAL_MODEL PHASE.
 *
 * This action provides FOUNDATIONAL explanation for students who have
 * NO mental model of the concept. It is NOT an agent - it does not
 * reason or decide strategy.
 *
 * CRITICAL CONSTRAINTS (NON-NEGOTIABLE):
 * - NO questions (student cannot reason yet)
 * - NO analogies/metaphors (adds cognitive load)
 * - NO verification requests
 * - NO "think about" or "consider" language
 * - ONLY literal, direct explanations
 *
 * Output structure:
 * - Definition: What the concept IS
 * - Mechanism: HOW it works
 * - Example: ONE concrete, literal example
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class direct_explanation extends base_action {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string {
        return 'direct_explanation';
    }

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Provide direct, literal explanation without questions or analogies';
    }

    /**
     * Check if this is a guidance action.
     *
     * @return bool True - direct explanation counts toward guidance attempts.
     */
    public function is_guidance_action(): bool {
        return true;
    }

    /**
     * Get the ISOLATED system prompt for NO_MENTAL_MODEL phase.
     *
     * This prompt is STRICTLY different from other actions:
     * - NO questions allowed
     * - NO analogies allowed
     * - ONLY literal, direct language
     *
     * @param agent_config $config Agent configuration.
     * @return string Minimal system prompt.
     */
    protected function get_isolated_system_prompt(agent_config $config): string {
        $agentcontext = $config->build_agent_context();
        $gradelevel = $agentcontext['student']['grade_level'] ?? 'secondary';
        $language = $agentcontext['behaviour']['response_language'] ?? 'English';

        return <<<PROMPT
You are providing a FOUNDATIONAL explanation for a student who has NO mental model of this concept.

CRITICAL RULES (MANDATORY - NO EXCEPTIONS):
- Write for a {$gradelevel} student
- Respond in {$language}
- Use ONLY literal, direct language
- Assume ZERO prior knowledge
- Do NOT use analogies or metaphors
- Do NOT ask questions
- Do NOT request verification
- Do NOT say "think about" or "consider"
- Do NOT end with a question mark
- Do NOT use phrases like "Does that make sense?" or "Do you understand?"

STRUCTURE:
1. First: State what the concept IS in plain, literal terms
2. Then: Explain HOW it works step by step
3. Finally: Give ONE concrete, literal example

STYLE:
- Short, declarative sentences
- One idea per sentence
- No jargon without immediate definition
- Direct statements only
- Maximum 5 sentences total

You are building a foundation. The student cannot explore or reason yet. Be direct and literal.
PROMPT;
    }

    /**
     * Build the ISOLATED user prompt for direct explanation.
     *
     * CONTEXT ISOLATION: Only includes:
     * - The topic (from memory for continuity)
     * - The student's current message
     * - Strict output constraints (NO questions, NO analogies)
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

        // Use get_conversation_topic() for continuity.
        $topic = $this->get_conversation_topic($context, $memory);

        // Get modifiers from action policy.
        $modifiers = $context['modifiers'] ?? [];

        // Check if empathetic response is needed.
        $empathynote = '';
        if (!empty($modifiers['empathetic'])) {
            $empathynote = "Note: Student seems frustrated. Start with a brief, warm acknowledgment (max 5 words) before explaining.";
        }

        return <<<INSTRUCTION
Topic: {$topic}
Student said: "{$studentmessage}"
{$empathynote}

The student has NO mental model of {$topic}. They cannot reason about it yet.

TASK: Explain {$topic} directly and literally.

MANDATORY RULES:
- Maximum 5 sentences total
- Sentence 1: Define what {$topic} IS (one clear, literal definition)
- Sentences 2-3: Explain the core mechanism or rule in simple terms
- Sentences 4-5: Give ONE concrete, literal example
- Use ONLY declarative sentences
- No metaphors, no analogies, no comparisons
- No questions of any kind
- No verification requests

FORBIDDEN PHRASES (DO NOT USE):
- "Think about..."
- "Can you see..."
- "Does that make sense?"
- "It's like..." (analogy)
- "Imagine..."
- "Consider..."
- Any question mark (?)

GOOD EXAMPLE OUTPUT:
"Fractions are numbers that show parts of a whole. When something is divided into equal pieces, a fraction tells you how many pieces you have. The top number shows how many pieces you took. The bottom number shows how many total pieces there are. If you cut a pizza into 4 slices and take 1 slice, you have 1/4 of the pizza."

Write a direct, literal explanation now (5 sentences max, NO QUESTIONS):
INSTRUCTION;
    }

    /**
     * Build focused instruction for direct explanation.
     *
     * Used when arguments are provided directly.
     *
     * @param string $concept The concept to explain.
     * @param string $studentmessage The student's original message.
     * @return string Focused instruction.
     */
    protected function build_focused_instruction(string $concept, string $studentmessage): string {
        return <<<INSTRUCTION
TASK: Provide a FOUNDATIONAL explanation of "{$concept}"

STUDENT SAID: "{$studentmessage}"

The student has NO prior understanding. Build their foundation.

STRICT OUTPUT FORMAT:
1. First sentence: Define what {$concept} IS in literal terms
2. Second/third sentences: Explain HOW it works, step by step
3. Fourth/fifth sentences: ONE concrete example using real numbers or objects

MANDATORY CONSTRAINTS:
- Maximum 5 sentences
- NO questions
- NO analogies or metaphors
- NO "think about" or "consider"
- ONLY literal, direct statements
- SHORT sentences, one idea each

Generate ONLY the direct explanation. Stop after the example.
INSTRUCTION;
    }
}
