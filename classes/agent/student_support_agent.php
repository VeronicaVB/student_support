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

namespace local_student_support\agent;

use local_student_support\agent\actions\action_interface;
use local_student_support\agent\actions\base_action;
use local_student_support\agent\prompts\system_prompt;
use local_student_support\ai\openai_client;
use local_student_support\ai\tool_registry;
use local_student_support\ai\function_call_handler;
use local_student_support\rules\academic_integrity;
use local_student_support\rules\privacy;
use local_student_support\rules\tone;

defined('MOODLE_INTERNAL') || die();

/**
 * Student Support Agent - GAME Loop Implementation.
 *
 * This is the main orchestrator for the Student Support Agent.
 * Implements the GAME loop pattern:
 * - Gather: Collect input and context
 * - Analyze: Detect intent and evaluate rules
 * - Match: Select appropriate action (via LLM function calling OR rule-based)
 * - Execute: Perform action and generate response
 *
 * IMPORTANT: This class orchestrates the agent logic. All decision-making
 * rules and constraints are defined in PHP, NOT delegated to the AI model.
 * The LLM is used ONLY for:
 * - Structured routing (function calling / tool selection)
 * - Language generation within strict constraints
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_support_agent {

    /** @var bool Use LLM for action selection via function calling. */
    private const USE_LLM_ROUTING = true;

    /** @var agent_config Agent configuration. */
    private agent_config $config;

    /** @var agent_memory Agent memory/state. */
    private agent_memory $memory;

    /** @var intent_detector Intent detection handler. */
    private intent_detector $intentdetector;

    /** @var action_selector Action selection handler. */
    private action_selector $actionselector;

    /** @var tool_registry Tool registry for LLM function calling. */
    private tool_registry $toolregistry;

    /** @var function_call_handler Function call handler. */
    private function_call_handler $functionhandler;

    /** @var openai_client OpenAI client. */
    private openai_client $llmclient;

    /** @var academic_integrity Academic integrity rules. */
    private academic_integrity $academicintegrityrules;

    /** @var privacy Privacy rules. */
    private privacy $privacyrules;

    /** @var tone Tone rules. */
    private tone $tonerules;

    /** @var bool Whether the agent is initialized. */
    private bool $initialized = false;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @param string|null $sessionid Existing session ID or null for new session.
     */
    public function __construct(int $courseid, int $userid, ?string $sessionid = null) {
        $this->config = new agent_config($courseid, $userid);

        if ($sessionid === null) {
            $sessionid = agent_memory::generate_session_id($userid, $courseid);
        }

        $this->memory = new agent_memory($sessionid, $userid, $courseid);

        $this->intentdetector = new intent_detector();
        $this->actionselector = new action_selector($this->config);

        // Initialize AI components.
        $this->toolregistry = new tool_registry();
        $this->functionhandler = new function_call_handler($this->toolregistry);
        $this->llmclient = new openai_client();

        // Initialize rules.
        $this->academicintegrityrules = new academic_integrity();
        $this->privacyrules = new privacy();
        $this->tonerules = new tone($this->config);

        $this->initialized = true;
    }

    /**
     * Check if the agent is ready to process messages.
     *
     * @return bool True if ready.
     */
    public function is_ready(): bool {
        return $this->initialized
            && $this->config->is_enabled()
            && $this->config->is_enabled_for_course()
            && $this->config->is_configured();
    }

    /**
     * Get the reason why the agent is not ready.
     *
     * @return string|null Error message or null if ready.
     */
    public function get_not_ready_reason(): ?string {
        if (!$this->initialized) {
            return get_string('error:notconfigured', 'local_student_support');
        }

        if (!$this->config->is_enabled()) {
            return get_string('error:notconfigured', 'local_student_support');
        }

        if (!$this->config->is_enabled_for_course()) {
            return get_string('error:coursenotconfigured', 'local_student_support');
        }

        if (!$this->config->is_configured()) {
            return get_string('error:notconfigured', 'local_student_support');
        }

        return null;
    }

    /**
     * Process a user message through the GAME loop.
     *
     * @param string $usermessage The user's message.
     * @return array Response array with 'success', 'message', and 'metadata'.
     */
    public function process_message(string $usermessage): array {
        if (!$this->is_ready()) {
            return [
                'success' => false,
                'message' => $this->get_not_ready_reason(),
                'metadata' => ['error' => 'agent_not_ready'],
            ];
        }

        try {
            // GAME Loop execution.
            $context = $this->gather($usermessage);
            $analysis = $this->analyze($context);

            // Check if rules block the request.
            $blocked = $this->check_rule_blocks($analysis);
            if ($blocked !== null) {
                return $this->handle_blocked_request($blocked, $context, $analysis);
            }

            // Use LLM routing or rule-based action selection.
            if (self::USE_LLM_ROUTING && $this->llmclient->is_configured()) {
                return $this->process_with_llm_routing($context, $analysis);
            } else {
                return $this->process_with_rule_based_routing($context, $analysis);
            }

        } catch (\Exception $e) {
            debugging("Student Support Agent error: " . $e->getMessage(), DEBUG_DEVELOPER);

            return [
                'success' => false,
                'message' => get_string('error:apierror', 'local_student_support'),
                'metadata' => ['error' => 'exception', 'details' => $e->getMessage()],
            ];
        }
    }

    /**
     * Process message using LLM for action routing via function calling.
     *
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function process_with_llm_routing(array $context, array $analysis): array {
        // Build system prompt.
        $systemprompt = system_prompt::build($this->config, $this->memory, $analysis);

        // Get conversation messages.
        $messages = $this->memory->get_context_messages();

        // Get tools from registry.
        $tools = $this->toolregistry->get_tools();

        // Call LLM with function calling.
        $response = $this->llmclient->ask($systemprompt, $messages, $tools);

        // Handle the response.
        $result = $this->functionhandler->handle_response($response);

        return $this->process_llm_result($result, $context, $analysis);
    }

    /**
     * Process the LLM result and execute appropriate action.
     *
     * @param array $result Processed result from function handler.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function process_llm_result(array $result, array $context, array $analysis): array {
        switch ($result['type']) {
            case 'tool_call':
                return $this->execute_tool_call($result, $context, $analysis);

            case 'direct_response':
                return $this->handle_direct_response($result, $context, $analysis);

            case 'text':
                // LLM returned text without tool call - use as response.
                return $this->finalize_response($result['content'], 'llm_text', $context, $analysis);

            case 'error':
                // Fall back to rule-based routing on error.
                debugging("LLM error, falling back to rule-based: " . ($result['error'] ?? 'unknown'), DEBUG_DEVELOPER);
                return $this->process_with_rule_based_routing($context, $analysis);

            default:
                return $this->process_with_rule_based_routing($context, $analysis);
        }
    }

    /**
     * Execute a tool call selected by the LLM.
     *
     * @param array $result Tool call result.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function execute_tool_call(array $result, array $context, array $analysis): array {
        $actionclass = $result['action_class'];
        $arguments = $result['arguments'] ?? [];

        // Validate the action class exists.
        if (!class_exists($actionclass)) {
            debugging("Action class not found: {$actionclass}", DEBUG_DEVELOPER);
            return $this->process_with_rule_based_routing($context, $analysis);
        }

        // Instantiate and execute the action.
        $action = new $actionclass();

        if (!($action instanceof base_action)) {
            debugging("Invalid action class: {$actionclass}", DEBUG_DEVELOPER);
            return $this->process_with_rule_based_routing($context, $analysis);
        }

        // Record the action.
        $this->memory->set_last_action($action->get_name());

        // Execute with arguments from the LLM.
        $actionresult = $action->execute_with_arguments(
            $arguments,
            $context,
            $analysis,
            $this->config,
            $this->memory
        );

        return $this->finalize_action_result($action, $actionresult, $context, $analysis);
    }

    /**
     * Handle a direct response from the LLM (no action needed).
     *
     * @param array $result Direct response result.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function handle_direct_response(array $result, array $context, array $analysis): array {
        $message = $result['content'] ?? '';
        $responsetype = $result['response_type'] ?? 'acknowledgment';

        $this->memory->set_last_action('respond_directly');

        return $this->finalize_response($message, "direct_{$responsetype}", $context, $analysis);
    }

    /**
     * Process message using rule-based action selection (fallback).
     *
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function process_with_rule_based_routing(array $context, array $analysis): array {
        // Select action based on rules.
        $action = $this->actionselector->select(
            $analysis['intent'],
            $analysis['current_state'],
            $this->memory
        );

        // Record and execute.
        $this->memory->set_last_action($action->get_name());

        $result = $action->execute($context, $analysis, $this->config, $this->memory);

        return $this->finalize_action_result($action, $result, $context, $analysis);
    }

    /**
     * Finalize an action result and update memory.
     *
     * @param action_interface $action The executed action.
     * @param array $result Action result.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function finalize_action_result(
        action_interface $action,
        array $result,
        array $context,
        array $analysis
    ): array {
        if ($result['success']) {
            // Add assistant message to memory.
            $this->memory->add_message(
                agent_memory::ROLE_ASSISTANT,
                $result['message'],
                $result['metadata'] ?? []
            );

            // Increment guidance attempts if this was a guidance action.
            if ($action->is_guidance_action()) {
                $this->memory->increment_guidance_attempts();
            }

            // Update state after action.
            $this->update_state_after_action($action, $result);
        }

        // Persist memory.
        $this->memory->save($this->config->should_log_conversations());

        return $result;
    }

    /**
     * Finalize a response and update memory.
     *
     * @param string $message Response message.
     * @param string $actionname Action name for metadata.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function finalize_response(
        string $message,
        string $actionname,
        array $context,
        array $analysis
    ): array {
        // Add to memory.
        $this->memory->add_message(agent_memory::ROLE_ASSISTANT, $message);
        $this->memory->save($this->config->should_log_conversations());

        return [
            'success' => true,
            'message' => $message,
            'metadata' => [
                'action' => $actionname,
                'session_id' => $this->memory->get_session_id(),
            ],
        ];
    }

    /**
     * GATHER phase: Collect input and context.
     *
     * @param string $usermessage The user's message.
     * @return array Gathered context.
     */
    private function gather(string $usermessage): array {
        // Store the user message in memory.
        $this->memory->add_message(agent_memory::ROLE_USER, $usermessage);

        // Build complete context.
        return [
            'user_message' => $usermessage,
            'agent_context' => $this->config->build_agent_context(),
            'memory_summary' => $this->memory->get_memory_summary(),
            'conversation_history' => $this->memory->get_context_messages(),
            'is_new_conversation' => $this->memory->is_new_conversation(),
        ];
    }

    /**
     * ANALYZE phase: Detect intent and evaluate rules.
     *
     * @param array $context Gathered context.
     * @return array Analysis results.
     */
    private function analyze(array $context): array {
        // Detect user intent.
        $intent = $this->intentdetector->detect($context['user_message'], $context);
        $this->memory->set_last_intent($intent['type']);

        // Update state based on intent.
        $this->update_state_for_intent($intent);

        // Evaluate rules.
        $ruleresults = $this->evaluate_rules($context, $intent);

        // Check if escalation is needed.
        $shouldescalate = $this->memory->should_escalate($this->config->get_max_attempts());

        return [
            'intent' => $intent,
            'rules' => $ruleresults,
            'should_escalate' => $shouldescalate,
            'current_state' => $this->memory->get_current_state(),
            'guidance_attempts' => $this->memory->get_guidance_attempts(),
        ];
    }

    /**
     * Check if any rule blocks the request.
     *
     * @param array $analysis Analysis results.
     * @return array|null Blocking rule result or null.
     */
    private function check_rule_blocks(array $analysis): ?array {
        foreach ($analysis['rules'] as $rulename => $result) {
            if ($result['blocked']) {
                return [
                    'rule' => $rulename,
                    'result' => $result,
                ];
            }
        }
        return null;
    }

    /**
     * Handle a blocked request.
     *
     * @param array $blocked Blocking information.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function handle_blocked_request(array $blocked, array $context, array $analysis): array {
        $message = $blocked['result']['message'] ?? get_string('message:refusedirectanswer', 'local_student_support');

        $this->memory->set_last_action('rule_block_' . $blocked['rule']);

        return $this->finalize_response($message, 'blocked_' . $blocked['rule'], $context, $analysis);
    }

    /**
     * Evaluate all rules against the current context and intent.
     *
     * @param array $context Current context.
     * @param array $intent Detected intent.
     * @return array Rule evaluation results.
     */
    private function evaluate_rules(array $context, array $intent): array {
        return [
            'academic_integrity' => $this->academicintegrityrules->evaluate($context, $intent),
            'privacy' => $this->privacyrules->evaluate($context, $intent),
            'tone' => $this->tonerules->evaluate($context, $intent),
        ];
    }

    /**
     * Update agent state based on detected intent.
     *
     * @param array $intent Detected intent.
     * @return void
     */
    private function update_state_for_intent(array $intent): void {
        $currentstate = $this->memory->get_current_state();

        if (!empty($intent['topic'])) {
            $this->memory->set_current_topic($intent['topic']);
        }

        switch ($intent['type']) {
            case intent_detector::INTENT_ASK_QUESTION:
            case intent_detector::INTENT_NEED_HELP:
                if ($currentstate === agent_memory::STATE_NEW) {
                    $this->memory->set_current_state(agent_memory::STATE_UNDERSTANDING);
                }
                break;

            case intent_detector::INTENT_CONFIRM_UNDERSTANDING:
                if ($currentstate === agent_memory::STATE_GUIDING) {
                    $this->memory->set_current_state(agent_memory::STATE_CHECKING);
                }
                break;

            case intent_detector::INTENT_EXPRESS_FRUSTRATION:
                $this->memory->set_data('student_frustrated', true);
                break;

            case intent_detector::INTENT_END_CONVERSATION:
                $this->memory->set_current_state(agent_memory::STATE_COMPLETED);
                break;
        }
    }

    /**
     * Update agent state after action execution.
     *
     * @param action_interface $action Executed action.
     * @param array $result Action result.
     * @return void
     */
    private function update_state_after_action(action_interface $action, array $result): void {
        $currentstate = $this->memory->get_current_state();

        if ($currentstate === agent_memory::STATE_UNDERSTANDING && $action->is_guidance_action()) {
            $this->memory->set_current_state(agent_memory::STATE_GUIDING);
        }

        if ($action->get_name() === 'escalate') {
            $this->memory->set_current_state(agent_memory::STATE_ESCALATING);
        }
    }

    /**
     * Get the current session ID.
     *
     * @return string Session ID.
     */
    public function get_session_id(): string {
        return $this->memory->get_session_id();
    }

    /**
     * Get the agent configuration.
     *
     * @return agent_config Configuration instance.
     */
    public function get_config(): agent_config {
        return $this->config;
    }

    /**
     * Get the agent memory.
     *
     * @return agent_memory Memory instance.
     */
    public function get_memory(): agent_memory {
        return $this->memory;
    }

    /**
     * Get a welcome message for new conversations.
     *
     * @return array Response array.
     */
    public function get_welcome_message(): array {
        if (!$this->is_ready()) {
            return [
                'success' => false,
                'message' => $this->get_not_ready_reason(),
                'metadata' => ['error' => 'agent_not_ready'],
            ];
        }

        $message = get_string('message:welcome', 'local_student_support');

        $this->memory->add_message(agent_memory::ROLE_ASSISTANT, $message);
        $this->memory->save($this->config->should_log_conversations());

        return [
            'success' => true,
            'message' => $message,
            'metadata' => [
                'action' => 'welcome',
                'session_id' => $this->get_session_id(),
            ],
        ];
    }

    /**
     * End the current conversation and save summary.
     *
     * @param string|null $outcome Optional outcome description.
     * @return void
     */
    public function end_conversation(?string $outcome = null): void {
        $this->memory->set_current_state(agent_memory::STATE_COMPLETED);

        $summary = sprintf(
            'Conversation with %d messages. Topics: %s. Last state: %s.',
            $this->memory->get_message_count(),
            implode(', ', $this->memory->get_data('discussed_topics', ['general'])),
            $this->memory->get_current_state()
        );

        $this->memory->save_summary($summary, $outcome);
        $this->memory->save(true);
    }
}
