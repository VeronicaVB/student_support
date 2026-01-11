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
 * Student Support Agent - GAME Framework Implementation.
 *
 * This is the main orchestrator for the Student Support Agent.
 * Implements the GAME framework (Goal, Actions, Memory, Environment):
 *
 * GAME FRAMEWORK:
 * - Goal: Define the pedagogical objective (guide without giving answers)
 * - Actions: Available pedagogical actions the agent can take
 * - Memory: Conversation history, cognitive state, and session data
 * - Environment: Course context, user context, curriculum configuration
 *
 * The agent processes each message through this framework:
 * 1. ENVIRONMENT: Gather context (course, user, curriculum, conversation)
 * 2. MEMORY: Load/update conversation state and cognitive state
 * 3. GOAL: Evaluate rules and determine pedagogical objective
 * 4. ACTIONS: Select and execute appropriate pedagogical action
 *
 * STATE-DRIVEN DESIGN:
 * - cognitive_state: Tracks WHERE the student is in their learning journey
 * - signal_detector: Detects boolean signals from user messages
 * - state_transition_engine: Computes state changes based on signals
 * - action_policy: Central policy function that decides actions based on state
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

    /** @var signal_detector Signal detection handler. */
    private signal_detector $signaldetector;

    /** @var state_transition_engine State transition engine. */
    private state_transition_engine $transitionengine;

    /** @var action_policy Action policy for decision making. */
    private action_policy $actionpolicy;

    /** @var cognitive_state Current cognitive state. */
    private cognitive_state $cognitivestate;

    /** @var intent_detector Legacy intent detector (for backward compatibility). */
    private intent_detector $intentdetector;

    /** @var action_selector Action selection handler (legacy fallback). */
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

        // Initialize new state-driven components.
        $this->signaldetector = new signal_detector();
        $this->transitionengine = new state_transition_engine($this->config->get_max_attempts());
        $this->actionpolicy = new action_policy(action_policy::APPROACH_SOCRATIC);

        // Initialize cognitive state from memory or fresh.
        $savedstate = $this->memory->get_data('cognitive_state_data', null);
        if ($savedstate !== null) {
            $this->cognitivestate = cognitive_state::from_array($savedstate);
        } else {
            $this->cognitivestate = cognitive_state::from_memory_state($this->memory->get_current_state());
        }

        // Legacy components for backward compatibility.
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
     * Process a user message through the GAME framework.
     *
     * GAME Framework execution:
     * 1. ENVIRONMENT: Gather context (course, user, curriculum, conversation)
     * 2. MEMORY: Load/update conversation state and cognitive state
     * 3. GOAL: Evaluate rules and determine if request aligns with pedagogical goals
     * 4. ACTIONS: Select and execute appropriate pedagogical action
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
            // ============================================================
            // GAME FRAMEWORK EXECUTION
            // ============================================================

            // 1. ENVIRONMENT: Gather context (course, user, curriculum, conversation history).
            $environment = $this->gather_environment($usermessage);

            // 2. MEMORY: Update cognitive state and detect signals from user message.
            $memorystate = $this->update_memory($environment);

            // 3. GOAL: Evaluate rules and check if request aligns with pedagogical goals.
            $goalcheck = $this->evaluate_goal($environment, $memorystate);
            if ($goalcheck['blocked']) {
                return $this->handle_blocked_request($goalcheck['block_info'], $environment, $memorystate);
            }

            // 4. ACTIONS: Select and execute appropriate pedagogical action.
            return $this->execute_action($environment, $memorystate);

        } catch (\Exception $e) {
            debugging("Student Support Agent error: " . $e->getMessage(), DEBUG_DEVELOPER);

            return [
                'success' => false,
                'message' => get_string('error:apierror', 'local_student_support'),
                'metadata' => ['error' => 'exception', 'details' => $e->getMessage()],
            ];
        }
    }

    // =========================================================================
    // GAME FRAMEWORK METHODS
    // =========================================================================

    /**
     * ENVIRONMENT: Gather all contextual information.
     *
     * The Environment in GAME represents all external context:
     * - Course information
     * - User information
     * - Curriculum configuration
     * - Conversation history
     *
     * @param string $usermessage The user's message.
     * @return array Environment context.
     */
    private function gather_environment(string $usermessage): array {
        // Store the user message in memory.
        $this->memory->add_message(agent_memory::ROLE_USER, $usermessage);

        return [
            'user_message' => $usermessage,
            'agent_context' => $this->config->build_agent_context(),
            'conversation_history' => $this->memory->get_context_messages(),
            'is_new_conversation' => $this->memory->is_new_conversation(),
            'last_action' => $this->memory->get_last_action(),
        ];
    }

    /**
     * MEMORY: Update and retrieve memory state.
     *
     * Memory in GAME tracks:
     * - Cognitive state (where the student is in learning journey)
     * - Cognitive phase (NO_MODEL, PARTIAL_MODEL, FUNCTIONAL_MODEL)
     * - Signals detected from user messages
     * - Intent and topic detection
     * - Guidance attempts, explanation counts, and session data
     *
     * @param array $environment Environment context.
     * @return array Memory state including signals, phase, and cognitive state.
     */
    private function update_memory(array $environment): array {
        // Build signal detection context.
        $signalcontext = [
            'explanation_count' => $this->memory->get_explanation_count(),
            'last_action' => $environment['last_action'],
            'is_new_conversation' => $environment['is_new_conversation'],
        ];

        // Detect signals from user message with context.
        $signals = $this->signaldetector->detect($environment['user_message'], $signalcontext);

        // Check for understanding confirmation in signals.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            $this->memory->set_confirmed_understanding(true);
        }

        // Get tracking data for phase determination.
        $guidanceattempts = $this->memory->get_guidance_attempts();
        $explanationattempts = $this->memory->get_explanation_count();
        $hasconfirmed = $this->memory->has_confirmed_understanding();

        // Apply FULL transition (both phase and state).
        $this->transitionengine->apply_full_transition(
            $this->cognitivestate,
            $signals,
            $guidanceattempts,
            $explanationattempts,
            $hasconfirmed
        );

        // Legacy: also detect intent for backward compatibility.
        $intent = $this->intentdetector->detect($environment['user_message'], $environment);
        $this->memory->set_last_intent($intent['type']);

        // Update topic if detected.
        if (!empty($intent['topic'])) {
            $this->memory->set_current_topic($intent['topic']);
        }

        return [
            'signals' => $signals,
            'cognitive_phase' => $this->cognitivestate->get_cognitive_phase(),
            'cognitive_state' => $this->cognitivestate->get_state(),
            'previous_state' => $this->cognitivestate->get_previous_state(),
            'is_stuck' => $this->cognitivestate->is_stuck(),
            'needs_empathy' => $this->cognitivestate->needs_empathy(),
            'can_ask_questions' => $this->cognitivestate->can_ask_questions(),
            'can_explore' => $this->cognitivestate->can_explore(),
            'requires_direct_explanation' => $this->cognitivestate->requires_direct_explanation(),
            'intent' => $intent,
            'current_state' => $this->memory->get_current_state(),
            'guidance_attempts' => $guidanceattempts,
            'explanation_count' => $explanationattempts,
            'memory_summary' => $this->memory->get_memory_summary(),
        ];
    }

    /**
     * GOAL: Evaluate if request aligns with pedagogical goals.
     *
     * Goal in GAME defines:
     * - The pedagogical objective (guide without giving answers)
     * - Academic integrity constraints
     * - Privacy constraints
     * - Tone and behavior constraints
     *
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Goal evaluation result with blocked status.
     */
    private function evaluate_goal(array $environment, array $memorystate): array {
        // Evaluate all rules against the current context.
        $ruleresults = [
            'academic_integrity' => $this->academicintegrityrules->evaluate($environment, $memorystate['intent']),
            'privacy' => $this->privacyrules->evaluate($environment, $memorystate['intent']),
            'tone' => $this->tonerules->evaluate($environment, $memorystate['intent']),
        ];

        // Check if any rule blocks the request.
        foreach ($ruleresults as $rulename => $result) {
            if ($result['blocked']) {
                return [
                    'blocked' => true,
                    'block_info' => [
                        'rule' => $rulename,
                        'result' => $result,
                    ],
                    'rules' => $ruleresults,
                ];
            }
        }

        return [
            'blocked' => false,
            'block_info' => null,
            'rules' => $ruleresults,
        ];
    }

    /**
     * ACTIONS: Select and execute appropriate pedagogical action.
     *
     * Actions in GAME are the pedagogical interventions:
     * - explain_concept: Break down concepts
     * - ask_guiding_question: Socratic questioning
     * - give_example: Illustrative examples
     * - rephrase_instruction: Reformulate explanations
     * - give_practice_problem: Practice exercises
     *
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function execute_action(array $environment, array $memorystate): array {
        $signals = $memorystate['signals'];
        $guidanceattempts = $memorystate['guidance_attempts'];

        // Get action decision from central policy.
        $decision = $this->actionpolicy->decide_next_action(
            $this->cognitivestate,
            $signals,
            $guidanceattempts
        );

        // Log the decision for debugging.
        debugging("Action policy decision: " . $this->actionpolicy->describe_decision($decision), DEBUG_DEVELOPER);

        // Handle closing action (no class needed).
        if ($decision['action'] === action_policy::ACTION_CLOSE) {
            return $this->handle_closing_action($environment, $memorystate, $decision);
        }

        // Instantiate the action.
        $action = $this->actionpolicy->instantiate_action($decision);

        if ($action === null) {
            // Fallback to LLM routing if no action class.
            return $this->process_with_llm_routing($environment, $memorystate);
        }

        // Record the action.
        $this->memory->set_last_action($action->get_name());

        // Execute the action with modifiers from the policy.
        $result = $action->execute_with_modifiers(
            $decision['modifiers'],
            $environment,
            $memorystate,
            $this->config,
            $this->memory
        );

        return $this->finalize_action_result($action, $result, $environment, $memorystate);
    }

    // =========================================================================
    // FALLBACK ROUTING METHODS
    // =========================================================================

    /**
     * Handle the closing action.
     *
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @param array $decision Action decision.
     * @return array Response array.
     */
    private function handle_closing_action(array $environment, array $memorystate, array $decision): array {
        $message = get_string('message:closingconversation', 'local_student_support');

        $this->memory->set_last_action('close_conversation');
        $this->memory->set_current_state(agent_memory::STATE_COMPLETED);

        return $this->finalize_response($message, 'close_conversation', $environment, $memorystate);
    }

    /**
     * Handle a blocked request (GOAL violation).
     *
     * @param array $blocked Blocking information.
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function handle_blocked_request(array $blocked, array $environment, array $memorystate): array {
        $message = $blocked['result']['message'] ?? get_string('message:refusedirectanswer', 'local_student_support');

        $this->memory->set_last_action('rule_block_' . $blocked['rule']);

        return $this->finalize_response($message, 'blocked_' . $blocked['rule'], $environment, $memorystate);
    }

    /**
     * Process message using LLM for action routing via function calling.
     *
     * This is the FALLBACK when state-driven policy doesn't apply.
     *
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function process_with_llm_routing(array $environment, array $memorystate): array {
        if (!self::USE_LLM_ROUTING || !$this->llmclient->is_configured()) {
            return $this->process_with_rule_based_routing($environment, $memorystate);
        }

        // Build system prompt.
        $systemprompt = system_prompt::build($this->config, $this->memory, $memorystate);

        // Get conversation messages.
        $messages = $this->memory->get_context_messages();

        // Get tools from registry.
        $tools = $this->toolregistry->get_tools();

        // Call LLM with function calling.
        $response = $this->llmclient->ask($systemprompt, $messages, $tools);

        // Handle the response.
        $result = $this->functionhandler->handle_response($response);

        return $this->process_llm_result($result, $environment, $memorystate);
    }

    /**
     * Process the LLM result and execute appropriate action.
     *
     * @param array $result Processed result from function handler.
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function process_llm_result(array $result, array $environment, array $memorystate): array {
        switch ($result['type']) {
            case 'tool_call':
                return $this->execute_tool_call($result, $environment, $memorystate);

            case 'direct_response':
                return $this->handle_direct_response($result, $environment, $memorystate);

            case 'text':
                // LLM returned text without tool call - use as response.
                return $this->finalize_response($result['content'], 'llm_text', $environment, $memorystate);

            case 'error':
                // Fall back to rule-based routing on error.
                debugging("LLM error, falling back to rule-based: " . ($result['error'] ?? 'unknown'), DEBUG_DEVELOPER);
                return $this->process_with_rule_based_routing($environment, $memorystate);

            default:
                return $this->process_with_rule_based_routing($environment, $memorystate);
        }
    }

    /**
     * Execute a tool call selected by the LLM.
     *
     * @param array $result Tool call result.
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function execute_tool_call(array $result, array $environment, array $memorystate): array {
        $actionclass = $result['action_class'];
        $arguments = $result['arguments'] ?? [];

        // Validate the action class exists.
        if (!class_exists($actionclass)) {
            debugging("Action class not found: {$actionclass}", DEBUG_DEVELOPER);
            return $this->process_with_rule_based_routing($environment, $memorystate);
        }

        // Instantiate and execute the action.
        $action = new $actionclass();

        if (!($action instanceof base_action)) {
            debugging("Invalid action class: {$actionclass}", DEBUG_DEVELOPER);
            return $this->process_with_rule_based_routing($environment, $memorystate);
        }

        // Record the action.
        $this->memory->set_last_action($action->get_name());

        // Execute with arguments from the LLM.
        $actionresult = $action->execute_with_arguments(
            $arguments,
            $environment,
            $memorystate,
            $this->config,
            $this->memory
        );

        return $this->finalize_action_result($action, $actionresult, $environment, $memorystate);
    }

    /**
     * Handle a direct response from the LLM (no action needed).
     *
     * @param array $result Direct response result.
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function handle_direct_response(array $result, array $environment, array $memorystate): array {
        $message = $result['content'] ?? '';
        $responsetype = $result['response_type'] ?? 'acknowledgment';

        $this->memory->set_last_action('respond_directly');

        return $this->finalize_response($message, "direct_{$responsetype}", $environment, $memorystate);
    }

    /**
     * Process message using rule-based action selection (fallback).
     *
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function process_with_rule_based_routing(array $environment, array $memorystate): array {
        // Select action based on rules.
        $action = $this->actionselector->select(
            $memorystate['intent'],
            $memorystate['current_state'],
            $this->memory
        );

        // Record and execute.
        $this->memory->set_last_action($action->get_name());

        $result = $action->execute($environment, $memorystate, $this->config, $this->memory);

        return $this->finalize_action_result($action, $result, $environment, $memorystate);
    }

    // =========================================================================
    // MEMORY PERSISTENCE METHODS
    // =========================================================================

    /**
     * Finalize an action result and update memory.
     *
     * @param action_interface $action The executed action.
     * @param array $result Action result.
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function finalize_action_result(
        action_interface $action,
        array $result,
        array $environment,
        array $memorystate
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

            // Increment explanation count for explanation-type actions.
            $actionname = $action->get_name();
            if (in_array($actionname, ['explain_concept', 'direct_explanation', 'rephrase_instruction'])) {
                $this->memory->increment_explanation_count();
            }

            // Update state after action.
            $this->update_state_after_action($action, $result, $memorystate);

            // Add cognitive phase to metadata.
            $result['metadata']['cognitive_phase'] = $this->cognitivestate->get_cognitive_phase();
        }

        // Persist memory and cognitive state.
        $this->save_state();

        return $result;
    }

    /**
     * Finalize a response and update memory.
     *
     * @param string $message Response message.
     * @param string $actionname Action name for metadata.
     * @param array $environment Environment context.
     * @param array $memorystate Memory state.
     * @return array Response array.
     */
    private function finalize_response(
        string $message,
        string $actionname,
        array $environment,
        array $memorystate
    ): array {
        // Add to memory.
        $this->memory->add_message(agent_memory::ROLE_ASSISTANT, $message);

        // Persist memory and cognitive state.
        $this->save_state();

        return [
            'success' => true,
            'message' => $message,
            'metadata' => [
                'action' => $actionname,
                'session_id' => $this->memory->get_session_id(),
                'cognitive_state' => $this->cognitivestate->get_state(),
            ],
        ];
    }

    /**
     * Save state to memory.
     *
     * @return void
     */
    private function save_state(): void {
        // Save cognitive state data.
        $this->memory->set_data('cognitive_state_data', $this->cognitivestate->to_array());

        // Persist memory.
        $this->memory->save($this->config->should_log_conversations());
    }

    /**
     * Update agent state after action execution.
     *
     * @param action_interface $action Executed action.
     * @param array $result Action result.
     * @param array $memorystate Memory state.
     * @return void
     */
    private function update_state_after_action(action_interface $action, array $result, array $memorystate): void {
        // Update legacy memory state based on cognitive state.
        $statemapping = [
            cognitive_state::NEW_QUESTION => agent_memory::STATE_UNDERSTANDING,
            cognitive_state::EXPLORING => agent_memory::STATE_GUIDING,
            cognitive_state::BLOCKED => agent_memory::STATE_GUIDING,
            cognitive_state::FRUSTRATED => agent_memory::STATE_GUIDING,
            cognitive_state::MAKING_PROGRESS => agent_memory::STATE_CHECKING,
            cognitive_state::READY_TO_CLOSE => agent_memory::STATE_COMPLETED,
            cognitive_state::SEEKING_ANSWER => agent_memory::STATE_GUIDING,
            cognitive_state::NEEDS_ESCALATION => agent_memory::STATE_ESCALATING,
        ];

        $cogstate = $this->cognitivestate->get_state();
        if (isset($statemapping[$cogstate])) {
            $this->memory->set_current_state($statemapping[$cogstate]);
        }

        // Handle escalation action.
        if ($action->get_name() === 'escalate') {
            $this->memory->set_current_state(agent_memory::STATE_ESCALATING);
        }
    }

    // =========================================================================
    // PUBLIC ACCESSOR METHODS
    // =========================================================================

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
     * Get the current cognitive state.
     *
     * @return cognitive_state Current cognitive state.
     */
    public function get_cognitive_state(): cognitive_state {
        return $this->cognitivestate;
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
        $this->save_state();

        return [
            'success' => true,
            'message' => $message,
            'metadata' => [
                'action' => 'welcome',
                'session_id' => $this->get_session_id(),
                'cognitive_state' => $this->cognitivestate->get_state(),
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
        $this->cognitivestate->transition_to(cognitive_state::READY_TO_CLOSE);

        $summary = sprintf(
            'Conversation with %d messages. Topics: %s. Final cognitive state: %s.',
            $this->memory->get_message_count(),
            implode(', ', $this->memory->get_data('discussed_topics', ['general'])),
            $this->cognitivestate->get_state()
        );

        $this->memory->save_summary($summary, $outcome);
        $this->save_state();
    }
}
