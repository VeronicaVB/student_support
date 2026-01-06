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
use local_student_support\agent\actions\explain_concept;
use local_student_support\agent\actions\rephrase_instruction;
use local_student_support\agent\actions\ask_guiding_question;
use local_student_support\agent\actions\give_example;

defined('MOODLE_INTERNAL') || die();

/**
 * Action selector for the Student Support Agent.
 *
 * Selects the appropriate action based on intent, state, and memory.
 * This is a rule-based selector that does NOT use AI for action selection.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_selector {

    /** @var agent_config Agent configuration. */
    private agent_config $config;

    /** @var array Registered action instances. */
    private array $actions;

    /**
     * Constructor.
     *
     * @param agent_config $config Agent configuration.
     */
    public function __construct(agent_config $config) {
        $this->config = $config;
        $this->register_actions();
    }

    /**
     * Register available actions.
     *
     * @return void
     */
    private function register_actions(): void {
        $this->actions = [
            'explain_concept' => new explain_concept(),
            'rephrase_instruction' => new rephrase_instruction(),
            'ask_guiding_question' => new ask_guiding_question(),
            'give_example' => new give_example(),
        ];
    }

    /**
     * Select an action based on intent, state, and memory.
     *
     * @param array $intent Detected intent.
     * @param string $state Current agent state.
     * @param agent_memory $memory Agent memory.
     * @return action_interface Selected action.
     */
    public function select(array $intent, string $state, agent_memory $memory): action_interface {
        $intenttype = $intent['type'];
        $approach = $this->config->get_pedagogical_approach();
        $lastaction = $memory->get_last_action();
        $guidanceattempts = $memory->get_guidance_attempts();

        // Select action based on intent.
        switch ($intenttype) {
            case intent_detector::INTENT_WANT_EXAMPLE:
                return $this->actions['give_example'];

            case intent_detector::INTENT_ASK_CLARIFICATION:
                return $this->actions['rephrase_instruction'];

            case intent_detector::INTENT_NEED_HELP:
            case intent_detector::INTENT_ASK_QUESTION:
                return $this->select_help_action($approach, $lastaction, $guidanceattempts);

            case intent_detector::INTENT_EXPRESS_FRUSTRATION:
                return $this->select_frustration_action($lastaction, $guidanceattempts);

            case intent_detector::INTENT_CONFIRM_UNDERSTANDING:
                return $this->actions['ask_guiding_question'];

            case intent_detector::INTENT_REQUEST_ANSWER:
                // This should be caught by rules, but as fallback, use guiding question.
                return $this->actions['ask_guiding_question'];

            default:
                // For general or unknown intents, use pedagogical approach.
                return $this->select_by_approach($approach, $lastaction);
        }
    }

    /**
     * Select action for help-related intents.
     *
     * @param string $approach Pedagogical approach.
     * @param string|null $lastaction Last action taken.
     * @param int $guidanceattempts Number of guidance attempts.
     * @return action_interface Selected action.
     */
    private function select_help_action(string $approach, ?string $lastaction, int $guidanceattempts): action_interface {
        // If first attempt, start with explanation.
        if ($guidanceattempts === 0) {
            return $this->actions['explain_concept'];
        }

        // Rotate through actions to avoid repetition.
        $actionsequence = $this->get_action_sequence($approach);
        $index = $guidanceattempts % count($actionsequence);

        return $this->actions[$actionsequence[$index]];
    }

    /**
     * Select action for frustration-related intents.
     *
     * @param string|null $lastaction Last action taken.
     * @param int $guidanceattempts Number of guidance attempts.
     * @return action_interface Selected action.
     */
    private function select_frustration_action(?string $lastaction, int $guidanceattempts): action_interface {
        // When frustrated, try a different approach.
        if ($lastaction === 'explain_concept') {
            return $this->actions['give_example'];
        }

        if ($lastaction === 'ask_guiding_question') {
            return $this->actions['rephrase_instruction'];
        }

        // Default to simpler explanation.
        return $this->actions['rephrase_instruction'];
    }

    /**
     * Select action based on pedagogical approach.
     *
     * @param string $approach Pedagogical approach.
     * @param string|null $lastaction Last action taken.
     * @return action_interface Selected action.
     */
    private function select_by_approach(string $approach, ?string $lastaction): action_interface {
        switch ($approach) {
            case agent_config::APPROACH_SOCRATIC:
                return $this->actions['ask_guiding_question'];

            case agent_config::APPROACH_SCAFFOLDED:
                if ($lastaction !== 'explain_concept') {
                    return $this->actions['explain_concept'];
                }
                return $this->actions['give_example'];

            case agent_config::APPROACH_EXPLORATORY:
                return $this->actions['give_example'];

            default:
                return $this->actions['explain_concept'];
        }
    }

    /**
     * Get action sequence based on pedagogical approach.
     *
     * @param string $approach Pedagogical approach.
     * @return array Action names in sequence.
     */
    private function get_action_sequence(string $approach): array {
        switch ($approach) {
            case agent_config::APPROACH_SOCRATIC:
                return [
                    'explain_concept',
                    'ask_guiding_question',
                    'give_example',
                    'ask_guiding_question',
                    'rephrase_instruction',
                ];

            case agent_config::APPROACH_SCAFFOLDED:
                return [
                    'explain_concept',
                    'give_example',
                    'rephrase_instruction',
                    'ask_guiding_question',
                    'explain_concept',
                ];

            case agent_config::APPROACH_EXPLORATORY:
                return [
                    'give_example',
                    'ask_guiding_question',
                    'explain_concept',
                    'give_example',
                    'rephrase_instruction',
                ];

            default:
                return [
                    'explain_concept',
                    'ask_guiding_question',
                    'give_example',
                    'rephrase_instruction',
                ];
        }
    }

    /**
     * Get a blocked action response for when rules prevent an action.
     *
     * @param string $rulename Name of the rule that blocked.
     * @param array $result Rule evaluation result.
     * @return action_interface Action that handles the blocked state.
     */
    public function get_blocked_action(string $rulename, array $result): action_interface {
        // For now, return a guiding question that redirects.
        // In future, could have specific blocked action handlers.
        return $this->actions['ask_guiding_question'];
    }

    /**
     * Get the escalation action.
     *
     * @return action_interface Escalation action.
     */
    public function get_escalation_action(): action_interface {
        // For now, return ask_guiding_question which will handle escalation.
        // In future, could have a dedicated escalation action.
        return $this->actions['ask_guiding_question'];
    }

    /**
     * Get all registered actions.
     *
     * @return array Array of action instances.
     */
    public function get_all_actions(): array {
        return $this->actions;
    }

    /**
     * Get a specific action by name.
     *
     * @param string $name Action name.
     * @return action_interface|null Action or null if not found.
     */
    public function get_action(string $name): ?action_interface {
        return $this->actions[$name] ?? null;
    }
}
