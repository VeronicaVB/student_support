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
use local_student_support\ai\openai_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Base class for agent actions.
 *
 * IMPORTANT DESIGN PRINCIPLE:
 * Pedagogical actions are NOT agents. They are CONTROLLED RENDERERS.
 *
 * During action execution:
 * - The LLM must NOT reason freely
 * - The LLM must NOT decide pedagogical strategy
 * - The LLM must ONLY generate output under strict formatting constraints
 *
 * Context Isolation Rules:
 * - DO NOT pass the global system prompt
 * - DO NOT pass the full conversation history
 * - DO NOT pass agent rules, goals, or tool lists
 * - ONLY pass minimal, action-specific instructions
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_action implements action_interface {

    /**
     * Execute the action using the LLM.
     *
     * This method uses ISOLATED CONTEXT:
     * - Minimal system instruction (action-specific only)
     * - Single user instruction describing what to generate
     * - No agent context, no conversation history, no tools
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
        $client = new openai_client();

        if (!$client->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('error:notconfigured', 'local_student_support'),
                'metadata' => [
                    'action' => $this->get_name(),
                    'error' => 'api_not_configured',
                ],
            ];
        }

        // CONTEXT ISOLATION: Build minimal, action-specific prompt.
        $systemprompt = $this->get_isolated_system_prompt($config);
        $userprompt = $this->build_isolated_user_prompt($context, $analysis, $config, $memory);

        $messages = [
            [
                'role' => 'user',
                'content' => $userprompt,
            ],
        ];

        // Call the LLM with NO tools - this is a controlled text generator.
        $response = $client->ask($systemprompt, $messages, []);

        return $this->handle_llm_response($response, $config);
    }

    /**
     * Execute the action with policy modifiers.
     *
     * Called by the action_policy when state-driven routing is used.
     * Modifiers adjust behavior without full arguments.
     *
     * @param array $modifiers Modifiers from action_policy.
     * @param array $context Gathered context from GAME loop.
     * @param array $analysis Analysis results from GAME loop.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return array Response with 'success', 'message', and 'metadata'.
     */
    public function execute_with_modifiers(
        array $modifiers,
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): array {
        // Store modifiers for use in prompt building.
        $context['modifiers'] = $modifiers;

        // Delegate to standard execute method.
        return $this->execute($context, $analysis, $config, $memory);
    }

    /**
     * Execute the action with tool call arguments.
     *
     * Called when the LLM has selected this action via function calling.
     * Uses the same ISOLATED CONTEXT principle.
     *
     * @param array $arguments Arguments from the tool call.
     * @param array $context Gathered context from GAME loop.
     * @param array $analysis Analysis results from GAME loop.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return array Response with 'success', 'message', and 'metadata'.
     */
    public function execute_with_arguments(
        array $arguments,
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): array {
        $client = new openai_client();

        if (!$client->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('error:notconfigured', 'local_student_support'),
                'metadata' => [
                    'action' => $this->get_name(),
                    'error' => 'api_not_configured',
                ],
            ];
        }

        // CONTEXT ISOLATION: Use arguments to build focused prompt.
        $systemprompt = $this->get_isolated_system_prompt($config);
        $userprompt = $this->build_isolated_user_prompt_with_arguments($arguments, $context, $config);

        $messages = [
            [
                'role' => 'user',
                'content' => $userprompt,
            ],
        ];

        $response = $client->ask($systemprompt, $messages, []);

        return $this->handle_llm_response($response, $config);
    }

    /**
     * Get the ISOLATED system prompt for action execution.
     *
     * This is MINIMAL and action-specific. It does NOT include:
     * - Global agent system prompt
     * - Agent goals or constraints
     * - Tool definitions
     * - Conversation history
     *
     * NOTE: Questions are now CONDITIONAL based on cognitive phase.
     * The base prompt does NOT mandate questions - each action decides
     * based on modifiers passed from action_policy.
     *
     * @param agent_config $config Agent configuration.
     * @return string Minimal system prompt.
     */
    protected function get_isolated_system_prompt(agent_config $config): string {
        $agentcontext = $config->build_agent_context();
        $gradelevel = $agentcontext['student']['grade_level'] ?? 'secondary';
        $language = $agentcontext['behaviour']['response_language'] ?? 'English';

        // Minimal system instruction - formatting constraints only.
        // NOTE: No mandatory question rule - actions decide based on phase.
        return <<<PROMPT
You are generating a controlled educational response.

ROLE: Educational text generator (NOT a decision-making agent).

OUTPUT CONSTRAINTS (MANDATORY):
- Write for a {$gradelevel} student
- Respond in {$language}
- Maximum 3 short paragraphs
- No numbered lists with more than 3 items
- No bullet points
- Explain ONE concept only
- Do NOT provide complete answers or solutions
- Do NOT cover the full topic

STYLE:
- Professional but warm
- Simple, clear language
- Short sentences

You are a text renderer, not a teacher making decisions.
PROMPT;
    }

    /**
     * Build the ISOLATED user prompt for action execution.
     *
     * Subclasses MUST override this to provide action-specific prompts.
     * The prompt should be minimal and focused.
     *
     * IMPORTANT: Use get_conversation_topic() to get the topic, NOT regex extraction.
     * The topic should come from memory to maintain conversation continuity.
     *
     * @param array $context Gathered context (use sparingly).
     * @param array $analysis Analysis results (use sparingly).
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return string Focused user prompt.
     */
    abstract protected function build_isolated_user_prompt(
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): string;

    /**
     * Build the ISOLATED user prompt with tool arguments.
     *
     * Uses arguments from function calling to create a focused prompt.
     *
     * @param array $arguments Tool call arguments.
     * @param array $context Gathered context (use sparingly).
     * @param agent_config $config Agent configuration.
     * @return string Focused user prompt.
     */
    protected function build_isolated_user_prompt_with_arguments(
        array $arguments,
        array $context,
        agent_config $config
    ): string {
        // Extract key information from arguments.
        $concept = $arguments['concept'] ?? $arguments['topic'] ?? '';
        $studentmessage = $context['user_message'] ?? '';

        // If no concept provided, extract from student message.
        if (empty($concept)) {
            $concept = $this->extract_topic_from_message($studentmessage);
        }

        return $this->build_focused_instruction($concept, $studentmessage);
    }

    /**
     * Build a focused instruction for this action.
     *
     * Subclasses should override this with action-specific instructions.
     *
     * @param string $concept The concept/topic to address.
     * @param string $studentmessage The student's original message.
     * @return string Focused instruction.
     */
    abstract protected function build_focused_instruction(string $concept, string $studentmessage): string;

    /**
     * Get the conversation topic from memory or context.
     *
     * PRIORITY ORDER:
     * 1. Current topic from memory (maintains conversation continuity)
     * 2. Topic from intent detection
     * 3. Fallback extraction from message (only for new questions)
     *
     * @param array $context Gathered context.
     * @param agent_memory $memory Agent memory.
     * @return string The topic to use.
     */
    protected function get_conversation_topic(array $context, agent_memory $memory): string {
        // Priority 1: Use topic from memory (conversation continuity).
        $memorytopic = $memory->get_current_topic();
        if (!empty($memorytopic)) {
            return $memorytopic;
        }

        // Priority 2: Use topic from intent detection (already analyzed).
        $intenttopic = $context['memory_summary']['current_topic'] ?? null;
        if (!empty($intenttopic)) {
            return $intenttopic;
        }

        // Priority 3: Fallback - extract from current message (new conversation only).
        $studentmessage = $context['user_message'] ?? '';
        return $this->extract_topic_from_message($studentmessage);
    }

    /**
     * Extract the topic from a student message.
     *
     * NOTE: This should only be used as a FALLBACK for new conversations.
     * For ongoing conversations, use get_conversation_topic() instead.
     *
     * @param string $message Student message.
     * @return string Extracted topic.
     */
    protected function extract_topic_from_message(string $message): string {
        // Remove common question prefixes.
        $cleaned = preg_replace('/^(what is|what are|how do|how does|can you explain|explain|tell me about|help me understand)\s+/i', '', $message);
        $cleaned = preg_replace('/\?.*$/', '', $cleaned);
        $cleaned = trim($cleaned);

        // Filter out meta-words (words about learning process, not subject matter).
        if ($this->is_meta_word($cleaned)) {
            return 'the topic';
        }

        // Limit length.
        if (mb_strlen($cleaned) > 100) {
            $cleaned = mb_substr($cleaned, 0, 100);
        }

        return $cleaned ?: 'the topic';
    }

    /**
     * Check if a word is a meta-word (about learning process, not subject matter).
     *
     * @param string $word The word to check.
     * @return bool True if this is a meta-word.
     */
    protected function is_meta_word(string $word): bool {
        $lowerword = strtolower(trim($word));

        // Extract first word if phrase.
        $firstword = explode(' ', $lowerword)[0];

        $metawords = [
            'it', 'this', 'that', 'everything', 'anything', 'something', 'nothing',
            'explanation', 'explanations', 'explain',
            'answer', 'answers',
            'question', 'questions',
            'example', 'examples',
            'problem', 'problems',
            'solution', 'solutions',
            'concept', 'concepts',
            'topic', 'topics',
            'lesson', 'lessons',
            'teacher', 'tutor',
            'homework', 'assignment',
            'help', 'hint', 'hints',
            'thing', 'things', 'stuff',
        ];

        return in_array($firstword, $metawords) || in_array($lowerword, $metawords);
    }

    /**
     * Handle the LLM response.
     *
     * @param array $response Response from OpenAI client.
     * @param agent_config $config Agent configuration.
     * @return array Formatted response for the agent.
     */
    protected function handle_llm_response(array $response, agent_config $config): array {
        if ($response['type'] === openai_client::RESPONSE_ERROR) {
            return [
                'success' => false,
                'message' => get_string('error:apierror', 'local_student_support'),
                'metadata' => [
                    'action' => $this->get_name(),
                    'error' => $response['error'] ?? 'unknown_error',
                    'api_metadata' => $response['metadata'] ?? [],
                ],
            ];
        }

        if ($response['type'] === openai_client::RESPONSE_TEXT) {
            return [
                'success' => true,
                'message' => $response['content'],
                'metadata' => [
                    'action' => $this->get_name(),
                    'model' => $response['metadata']['model'] ?? $config->get_model(),
                    'usage' => $response['metadata']['usage'] ?? null,
                    'duration_ms' => $response['metadata']['duration_ms'] ?? null,
                ],
            ];
        }

        return [
            'success' => false,
            'message' => get_string('error:apierror', 'local_student_support'),
            'metadata' => [
                'action' => $this->get_name(),
                'error' => 'unexpected_response_type',
                'response_type' => $response['type'],
            ],
        ];
    }

    /**
     * Get the last assistant message from context.
     *
     * Used to provide continuity context without full history.
     *
     * @param array $context Gathered context.
     * @return string|null Last assistant message or null.
     */
    protected function get_last_assistant_message(array $context): ?string {
        $history = $context['conversation_history'] ?? [];
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                return $history[$i]['content'] ?? null;
            }
        }
        return null;
    }

    /**
     * Build the prompt for this action.
     *
     * Required by action_interface. Delegates to build_isolated_user_prompt
     * to maintain context isolation principles.
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
        return $this->build_isolated_user_prompt($context, $analysis, $config, $memory);
    }
}
