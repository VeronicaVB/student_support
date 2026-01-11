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
use local_student_support\agent\actions\ask_guiding_question;
use local_student_support\agent\actions\give_example;
use local_student_support\agent\actions\give_practice_problem;
use local_student_support\agent\actions\rephrase_instruction;
use local_student_support\agent\actions\direct_explanation;

defined('MOODLE_INTERNAL') || die();

/**
 * Central action policy for the Student Support Agent.
 *
 * This is the SINGLE place where action selection happens.
 *
 * TWO-TIER DECISION PROCESS:
 * 1. PHASE-BASED ROUTING (first):
 *    - NO_MENTAL_MODEL → Direct explanation only
 *    - PARTIAL_MENTAL_MODEL → Explanation with optional questions
 *    - FUNCTIONAL_MENTAL_MODEL → Full Socratic method
 *
 * 2. STATE-BASED ROUTING (second):
 *    - Within the phase constraints, use emotional/situational state
 *
 * The policy receives:
 * - Current cognitive state (including phase)
 * - Detected signals
 * - Guidance attempt count
 * - Pedagogical approach preference
 *
 * It returns ONE pedagogical action.
 *
 * DESIGN RULES:
 * - NO if/else explosion for student phrasings
 * - Phrasings map to signals → signals inform state → state drives policy
 * - Policy is deterministic given the inputs
 * - Phase determines WHAT TYPE of response, state determines TONE
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_policy {

    // =========================================================================
    // ACTION IDENTIFIERS
    // =========================================================================

    public const ACTION_EXPLAIN = 'explain_concept';
    public const ACTION_DIRECT_EXPLAIN = 'direct_explanation';
    public const ACTION_GUIDE = 'ask_guiding_question';
    public const ACTION_EXAMPLE = 'give_example';
    public const ACTION_REPHRASE = 'rephrase_instruction';
    public const ACTION_REDIRECT = 'redirect_to_learning';
    public const ACTION_EMPATHIZE = 'empathize_and_scaffold';
    public const ACTION_ESCALATE = 'suggest_teacher';
    public const ACTION_CLOSE = 'close_conversation';
    public const ACTION_MICRO_SCAFFOLD = 'micro_scaffold';
    public const ACTION_PRACTICE_PROBLEM = 'give_practice_problem';

    // =========================================================================
    // PEDAGOGICAL APPROACHES
    // =========================================================================

    public const APPROACH_SOCRATIC = 'socratic';
    public const APPROACH_SCAFFOLDED = 'scaffolded';
    public const APPROACH_EXPLORATORY = 'exploratory';

    /** @var string Current pedagogical approach. */
    private string $approach;

    /**
     * Constructor.
     *
     * @param string $approach Pedagogical approach (socratic, scaffolded, exploratory).
     */
    public function __construct(string $approach = self::APPROACH_SOCRATIC) {
        $this->approach = $approach;
    }

    /**
     * Decide the next action based on state and signals.
     *
     * This is the CENTRAL POLICY FUNCTION.
     *
     * TWO-TIER DECISION:
     * 1. PHASE determines what TYPE of response (explanation vs exploration)
     * 2. STATE determines the tone and specific approach
     *
     * @param cognitive_state $state Current cognitive state.
     * @param array $signals Detected signals.
     * @param int $guidanceattempts Number of guidance attempts.
     * @return array Action decision with 'action', 'class', and 'modifiers'.
     */
    public function decide_next_action(
        cognitive_state $state,
        array $signals,
        int $guidanceattempts
    ): array {
        $currentstate = $state->get_state();
        $phase = $state->get_cognitive_phase();

        // =====================================================================
        // PRIORITY 0: Handle special states regardless of phase
        // =====================================================================
        if ($currentstate === cognitive_state::NEEDS_ESCALATION) {
            return $this->action_escalate();
        }

        if ($currentstate === cognitive_state::READY_TO_CLOSE) {
            return $this->action_close();
        }

        if ($currentstate === cognitive_state::SEEKING_ANSWER) {
            return $this->action_redirect();
        }

        // =====================================================================
        // TIER 1: PHASE-BASED ROUTING (determines TYPE of response)
        // =====================================================================

        // NO_MENTAL_MODEL: Direct explanation only - NO questions, NO analogies.
        if ($phase === cognitive_state::PHASE_NO_MODEL) {
            return $this->action_for_no_mental_model($signals, $state);
        }

        // PARTIAL_MENTAL_MODEL: Explanation with optional questions.
        if ($phase === cognitive_state::PHASE_PARTIAL_MODEL) {
            return $this->action_for_partial_model($signals, $state, $guidanceattempts);
        }

        // =====================================================================
        // TIER 2: FUNCTIONAL_MENTAL_MODEL - Full state-based routing
        // =====================================================================
        return $this->action_for_functional_model($state, $signals, $guidanceattempts);
    }

    // =========================================================================
    // PHASE-BASED ACTION SELECTION (New)
    // =========================================================================

    /**
     * Get action for NO_MENTAL_MODEL phase.
     *
     * CRITICAL: In this phase, the student has NO foundational understanding.
     * We MUST use direct explanation WITHOUT:
     * - Questions (they can't reason yet)
     * - Analogies (adds cognitive load)
     * - Verification requests
     *
     * @param array $signals Detected signals.
     * @param cognitive_state $state State object.
     * @return array Action decision.
     */
    private function action_for_no_mental_model(array $signals, cognitive_state $state): array {
        // Handle frustration with empathy BUT still use direct explanation.
        if ($state->get_state() === cognitive_state::FRUSTRATED) {
            return [
                'action' => self::ACTION_DIRECT_EXPLAIN,
                'class' => direct_explanation::class,
                'modifiers' => [
                    'no_questions' => true,
                    'no_analogies' => true,
                    'empathetic' => true,
                    'foundational' => true,
                ],
            ];
        }

        // Default: Direct, literal explanation.
        return $this->action_direct_explain();
    }

    /**
     * Get action for PARTIAL_MENTAL_MODEL phase.
     *
     * Student has a basic idea but incomplete understanding.
     * We can use:
     * - Explanations (with optional questions)
     * - Simple analogies (carefully)
     * - Simple examples
     *
     * We should NOT use:
     * - Socratic questioning (they're not ready for discovery)
     * - Practice problems (not ready to apply yet)
     *
     * @param array $signals Detected signals.
     * @param cognitive_state $state State object.
     * @param int $attempts Guidance attempts.
     * @return array Action decision.
     */
    private function action_for_partial_model(array $signals, cognitive_state $state, int $attempts): array {
        $currentstate = $state->get_state();

        // Handle frustration.
        if ($currentstate === cognitive_state::FRUSTRATED) {
            return [
                'action' => self::ACTION_EXPLAIN,
                'class' => explain_concept::class,
                'modifiers' => [
                    'optional_question' => true,
                    'empathetic' => true,
                    'simple' => true,
                ],
            ];
        }

        // Student wants an example.
        if ($signals[signal_detector::SIGNAL_WANTS_EXAMPLE] ?? false) {
            return $this->action_example(['simple' => true, 'optional_question' => true]);
        }

        // Student is attempting to engage - encourage with gentle guidance.
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            return $this->action_guide(['gentle' => true, 'optional_question' => true]);
        }

        // Needs clarification - rephrase.
        if ($signals[signal_detector::SIGNAL_NEEDS_CLARIFICATION] ?? false) {
            return $this->action_rephrase(['optional_question' => true]);
        }

        // Blocked state - try different approach with simple explanation.
        if ($currentstate === cognitive_state::BLOCKED) {
            return $this->action_explain(['simple' => true, 'optional_question' => true]);
        }

        // Default: Explanation with optional question.
        return $this->action_explain(['optional_question' => true, 'allow_simple_analogy' => true]);
    }

    /**
     * Get action for FUNCTIONAL_MENTAL_MODEL phase.
     *
     * Student can reason about the concept. Full pedagogical repertoire available:
     * - Socratic questioning
     * - Practice problems
     * - Complex analogies
     * - Exploration
     *
     * This uses the original state-based routing.
     *
     * @param cognitive_state $state State object.
     * @param array $signals Detected signals.
     * @param int $guidanceattempts Guidance attempts.
     * @return array Action decision.
     */
    private function action_for_functional_model(
        cognitive_state $state,
        array $signals,
        int $guidanceattempts
    ): array {
        $currentstate = $state->get_state();

        // =====================================================================
        // STATE-BASED ACTION SELECTION (original logic, but in functional phase)
        // =====================================================================

        switch ($currentstate) {
            case cognitive_state::FRUSTRATED:
                return $this->action_for_frustrated($signals, $state);

            case cognitive_state::BLOCKED:
                return $this->action_for_blocked($signals, $state, $guidanceattempts);

            case cognitive_state::NEW_QUESTION:
                return $this->action_for_new_question($signals);

            case cognitive_state::EXPLORING:
                return $this->action_for_exploring($signals, $guidanceattempts);

            case cognitive_state::MAKING_PROGRESS:
                return $this->action_for_making_progress($signals);

            default:
                return $this->action_guide();
        }
    }

    /**
     * Get action for NEW_QUESTION state.
     *
     * @param array $signals Detected signals.
     * @return array Action decision.
     */
    private function action_for_new_question(array $signals): array {
        // If they want an example specifically.
        if ($signals[signal_detector::SIGNAL_WANTS_EXAMPLE] ?? false) {
            return $this->action_example();
        }

        // If they express confusion, start with explanation.
        if ($signals[signal_detector::SIGNAL_CONFUSION] ?? false) {
            return $this->action_explain();
        }

        // Based on pedagogical approach.
        switch ($this->approach) {
            case self::APPROACH_SOCRATIC:
                // Socratic: start with a question to probe understanding.
                return $this->action_guide();

            case self::APPROACH_EXPLORATORY:
                // Exploratory: start with an example.
                return $this->action_example();

            case self::APPROACH_SCAFFOLDED:
            default:
                // Scaffolded: start with explanation.
                return $this->action_explain();
        }
    }

    /**
     * Get action for EXPLORING state.
     *
     * @param array $signals Detected signals.
     * @param int $attempts Guidance attempts.
     * @return array Action decision.
     */
    private function action_for_exploring(array $signals, int $attempts): array {
        // Student wants to practice (highest priority in exploring state).
        if ($signals[signal_detector::SIGNAL_READY_TO_PRACTICE] ?? false) {
            return $this->action_practice_problem();
        }

        // Student needs clarification.
        if ($signals[signal_detector::SIGNAL_NEEDS_CLARIFICATION] ?? false) {
            return $this->action_rephrase();
        }

        // Student wants example.
        if ($signals[signal_detector::SIGNAL_WANTS_EXAMPLE] ?? false) {
            return $this->action_example();
        }

        // Student is attempting to answer/engage.
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            // Acknowledge and guide further.
            return $this->action_guide(['acknowledge_attempt' => true]);
        }

        // Uncertainty - depends on how many attempts.
        if ($signals[signal_detector::SIGNAL_UNCERTAINTY] ?? false) {
            if ($attempts >= 2) {
                // Multiple attempts with uncertainty = try different approach.
                return $this->action_rephrase();
            }
            // First uncertainty = gentle guiding question.
            return $this->action_guide(['gentle' => true]);
        }

        // Based on approach.
        switch ($this->approach) {
            case self::APPROACH_SOCRATIC:
                return $this->action_guide();

            case self::APPROACH_EXPLORATORY:
                // Alternate between examples and questions.
                return ($attempts % 2 === 0) ? $this->action_example() : $this->action_guide();

            case self::APPROACH_SCAFFOLDED:
            default:
                return $this->action_explain();
        }
    }

    /**
     * Get action for BLOCKED state.
     *
     * @param array $signals Detected signals.
     * @param cognitive_state $state State object.
     * @param int $attempts Guidance attempts.
     * @return array Action decision.
     */
    private function action_for_blocked(array $signals, cognitive_state $state, int $attempts): array {
        $blockedcount = $state->get_blocked_count();

        // Multiple blocks = use micro-scaffolding (very simple, closed questions).
        if ($blockedcount >= 2) {
            return $this->action_micro_scaffold();
        }

        // Student wants example when blocked = good sign, provide example.
        if ($signals[signal_detector::SIGNAL_WANTS_EXAMPLE] ?? false) {
            return $this->action_example();
        }

        // First time blocked = try rephrasing.
        if ($blockedcount === 1) {
            return $this->action_rephrase();
        }

        // Default for blocked: simple scaffolded question.
        return $this->action_guide(['simple' => true]);
    }

    /**
     * Get action for FRUSTRATED state.
     *
     * @param array $signals Detected signals.
     * @param cognitive_state $state State object.
     * @return array Action decision.
     */
    private function action_for_frustrated(array $signals, cognitive_state $state): array {
        // Empathize first, then scaffold.
        return [
            'action' => self::ACTION_EMPATHIZE,
            'class' => rephrase_instruction::class, // Uses rephrase with empathy modifier.
            'modifiers' => [
                'empathetic' => true,
                'acknowledge_frustration' => true,
                'simple_scaffold' => true,
            ],
        ];
    }

    /**
     * Get action for MAKING_PROGRESS state.
     *
     * @param array $signals Detected signals.
     * @return array Action decision.
     */
    private function action_for_making_progress(array $signals): array {
        // Student wants to practice - they're ready!
        if ($signals[signal_detector::SIGNAL_READY_TO_PRACTICE] ?? false) {
            return $this->action_practice_problem();
        }

        // Continue guiding to deepen understanding.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return $this->action_guide(['check_deeper' => true]);
        }

        // New question from progress = explain.
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return $this->action_explain();
        }

        // Default: continue with gentle guidance.
        return $this->action_guide(['build_on_progress' => true]);
    }

    // =========================================================================
    // ACTION BUILDERS
    // =========================================================================

    /**
     * Build direct explanation action.
     *
     * For NO_MENTAL_MODEL phase: literal, direct explanation
     * WITHOUT questions, analogies, or verification.
     *
     * @param array $modifiers Optional modifiers.
     * @return array Action decision.
     */
    private function action_direct_explain(array $modifiers = []): array {
        return [
            'action' => self::ACTION_DIRECT_EXPLAIN,
            'class' => direct_explanation::class,
            'modifiers' => array_merge([
                'no_questions' => true,
                'no_analogies' => true,
                'literal_only' => true,
                'foundational' => true,
            ], $modifiers),
        ];
    }

    /**
     * Build explain action.
     *
     * @param array $modifiers Optional modifiers.
     * @return array Action decision.
     */
    private function action_explain(array $modifiers = []): array {
        return [
            'action' => self::ACTION_EXPLAIN,
            'class' => explain_concept::class,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * Build guiding question action.
     *
     * @param array $modifiers Optional modifiers.
     * @return array Action decision.
     */
    private function action_guide(array $modifiers = []): array {
        return [
            'action' => self::ACTION_GUIDE,
            'class' => ask_guiding_question::class,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * Build example action.
     *
     * @param array $modifiers Optional modifiers.
     * @return array Action decision.
     */
    private function action_example(array $modifiers = []): array {
        return [
            'action' => self::ACTION_EXAMPLE,
            'class' => give_example::class,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * Build rephrase action.
     *
     * @param array $modifiers Optional modifiers.
     * @return array Action decision.
     */
    private function action_rephrase(array $modifiers = []): array {
        return [
            'action' => self::ACTION_REPHRASE,
            'class' => rephrase_instruction::class,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * Build micro-scaffold action.
     *
     * Uses guiding question but with very simple, closed format.
     *
     * @return array Action decision.
     */
    private function action_micro_scaffold(): array {
        return [
            'action' => self::ACTION_MICRO_SCAFFOLD,
            'class' => ask_guiding_question::class,
            'modifiers' => [
                'micro' => true,
                'closed_question' => true,
                'very_simple' => true,
            ],
        ];
    }

    /**
     * Build practice problem action.
     *
     * Gives the student a problem to solve (for practice).
     *
     * @param array $modifiers Optional modifiers.
     * @return array Action decision.
     */
    private function action_practice_problem(array $modifiers = []): array {
        return [
            'action' => self::ACTION_PRACTICE_PROBLEM,
            'class' => give_practice_problem::class,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * Build redirect action (for answer requests).
     *
     * @return array Action decision.
     */
    private function action_redirect(): array {
        return [
            'action' => self::ACTION_REDIRECT,
            'class' => ask_guiding_question::class,
            'modifiers' => [
                'redirect' => true,
                'acknowledge_request' => true,
            ],
        ];
    }

    /**
     * Build escalate action.
     *
     * @return array Action decision.
     */
    private function action_escalate(): array {
        return [
            'action' => self::ACTION_ESCALATE,
            'class' => rephrase_instruction::class,
            'modifiers' => [
                'escalate' => true,
                'suggest_teacher' => true,
                'summarize' => true,
            ],
        ];
    }

    /**
     * Build close action.
     *
     * @return array Action decision.
     */
    private function action_close(): array {
        return [
            'action' => self::ACTION_CLOSE,
            'class' => null, // Direct response, no action class.
            'modifiers' => [
                'closing' => true,
            ],
        ];
    }

    /**
     * Instantiate the action class.
     *
     * @param array $decision Action decision from decide_next_action.
     * @return action_interface|null Action instance or null for direct responses.
     */
    public function instantiate_action(array $decision): ?action_interface {
        $class = $decision['class'] ?? null;

        if ($class === null) {
            return null;
        }

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Action class not found: {$class}");
        }

        return new $class();
    }

    /**
     * Get human-readable description of action decision.
     *
     * @param array $decision Action decision.
     * @return string Description.
     */
    public function describe_decision(array $decision): string {
        $action = $decision['action'] ?? 'unknown';
        $modifiers = $decision['modifiers'] ?? [];

        $desc = "Action: {$action}";
        if (!empty($modifiers)) {
            $mods = implode(', ', array_keys(array_filter($modifiers)));
            $desc .= " [modifiers: {$mods}]";
        }

        return $desc;
    }
}
