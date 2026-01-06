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
 * - Match: Select appropriate action
 * - Execute: Perform action and generate response
 *
 * IMPORTANT: This class orchestrates the agent logic. All decision-making
 * rules and constraints are defined in PHP, NOT delegated to the AI model.
 * Neuron AI is used ONLY for:
 * - Sending prompts to the AI provider
 * - Receiving and parsing responses
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_support_agent {

    /** @var agent_config Agent configuration. */
    private agent_config $config;

    /** @var agent_memory Agent memory/state. */
    private agent_memory $memory;

    /** @var intent_detector Intent detection handler. */
    private intent_detector $intentdetector;

    /** @var action_selector Action selection handler. */
    private action_selector $actionselector;

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
            $action = $this->match($analysis);
            $response = $this->execute($action, $context, $analysis);

            return $response;

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
     * GATHER phase: Collect input and context.
     *
     * @param string $usermessage The user's message.
     * @return array Gathered context.
     */
    private function gather(string $usermessage): array {
        // Store the user message in memory.
        $this->memory->add_message(agent_memory::ROLE_USER, $usermessage);

        // Build complete context.
        $context = [
            'user_message' => $usermessage,
            'agent_context' => $this->config->build_agent_context(),
            'memory_summary' => $this->memory->get_memory_summary(),
            'conversation_history' => $this->memory->get_context_messages(),
            'is_new_conversation' => $this->memory->is_new_conversation(),
        ];

        return $context;
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
     * MATCH phase: Select appropriate action.
     *
     * @param array $analysis Analysis results.
     * @return action_interface Selected action.
     */
    private function match(array $analysis): action_interface {
        // If any rule blocks the action, select a rule-based response.
        foreach ($analysis['rules'] as $rulename => $result) {
            if ($result['blocked']) {
                return $this->actionselector->get_blocked_action($rulename, $result);
            }
        }

        // If escalation is needed, select escalation action.
        if ($analysis['should_escalate']) {
            return $this->actionselector->get_escalation_action();
        }

        // Select action based on intent and state.
        $action = $this->actionselector->select(
            $analysis['intent'],
            $analysis['current_state'],
            $this->memory
        );

        return $action;
    }

    /**
     * EXECUTE phase: Perform action and generate response.
     *
     * @param action_interface $action The action to execute.
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @return array Response array.
     */
    private function execute(action_interface $action, array $context, array $analysis): array {
        // Record the action being taken.
        $this->memory->set_last_action($action->get_name());

        // Execute the action.
        // NOTE: The action will use Neuron AI to generate the response.
        // The action is responsible for building the prompt and calling the AI.
        $result = $action->execute($context, $analysis, $this->config, $this->memory);

        // If the action was successful, update memory.
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
     * Evaluate all rules against the current context and intent.
     *
     * @param array $context Current context.
     * @param array $intent Detected intent.
     * @return array Rule evaluation results.
     */
    private function evaluate_rules(array $context, array $intent): array {
        $results = [];

        // Academic integrity check.
        $results['academic_integrity'] = $this->academicintegrityrules->evaluate($context, $intent);

        // Privacy check.
        $results['privacy'] = $this->privacyrules->evaluate($context, $intent);

        // Tone check.
        $results['tone'] = $this->tonerules->evaluate($context, $intent);

        return $results;
    }

    /**
     * Update agent state based on detected intent.
     *
     * @param array $intent Detected intent.
     * @return void
     */
    private function update_state_for_intent(array $intent): void {
        $currentstate = $this->memory->get_current_state();

        // Update topic if detected.
        if (!empty($intent['topic'])) {
            $this->memory->set_current_topic($intent['topic']);
        }

        // State transitions based on intent.
        switch ($intent['type']) {
            case intent_detector::INTENT_ASK_QUESTION:
            case intent_detector::INTENT_NEED_HELP:
                if ($currentstate === agent_memory::STATE_NEW) {
                    $this->memory->set_current_state(agent_memory::STATE_UNDERSTANDING);
                }
                break;

            case intent_detector::INTENT_REQUEST_ANSWER:
                // Stay in current state, but this will trigger rule evaluation.
                break;

            case intent_detector::INTENT_CONFIRM_UNDERSTANDING:
                if ($currentstate === agent_memory::STATE_GUIDING) {
                    $this->memory->set_current_state(agent_memory::STATE_CHECKING);
                }
                break;

            case intent_detector::INTENT_EXPRESS_FRUSTRATION:
                // Adjust approach, but don't change state.
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

        // Transition to guiding after understanding.
        if ($currentstate === agent_memory::STATE_UNDERSTANDING && $action->is_guidance_action()) {
            $this->memory->set_current_state(agent_memory::STATE_GUIDING);
        }

        // If we're escalating, update state.
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

        // Generate a simple summary (in future, this could use AI).
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
