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

defined('MOODLE_INTERNAL') || die();

/**
 * State transition engine for cognitive states and phases.
 *
 * Handles TWO-TIER cognitive model:
 *
 * 1. COGNITIVE PHASES (new):
 *    - NO_MENTAL_MODEL: Student has no foundational understanding
 *    - PARTIAL_MENTAL_MODEL: Student has basic idea but incomplete
 *    - FUNCTIONAL_MENTAL_MODEL: Student can reason and explore
 *
 * 2. COGNITIVE STATES (existing):
 *    - NEW_QUESTION, EXPLORING, BLOCKED, FRUSTRATED, etc.
 *    - These represent emotional/situational context
 *
 * The phase determines WHAT TYPE of response (explanation vs exploration).
 * The state determines the TONE and APPROACH within that type.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_transition_engine {

    /** @var int Max attempts before escalation. */
    private int $maxattempts;

    /** @var int Max uncertainty signals before BLOCKED. */
    private const MAX_UNCERTAINTY_BEFORE_BLOCKED = 2;

    /** @var int Max blocked states before escalation. */
    private const MAX_BLOCKED_BEFORE_ESCALATION = 3;

    /**
     * Constructor.
     *
     * @param int $maxattempts Maximum guidance attempts before escalation.
     */
    public function __construct(int $maxattempts = 5) {
        $this->maxattempts = $maxattempts;
    }

    /**
     * Compute the next cognitive state.
     *
     * This is the central state machine logic.
     *
     * @param cognitive_state $currentstate Current cognitive state.
     * @param array $signals Detected signals from signal_detector.
     * @param int $guidanceattempts Number of guidance attempts so far.
     * @return string New state constant.
     */
    public function compute_next_state(
        cognitive_state $currentstate,
        array $signals,
        int $guidanceattempts
    ): string {
        $state = $currentstate->get_state();

        // =====================================================================
        // PRIORITY 1: Check for escalation conditions (any state)
        // =====================================================================
        if ($this->should_escalate($currentstate, $guidanceattempts)) {
            return cognitive_state::NEEDS_ESCALATION;
        }

        // =====================================================================
        // PRIORITY 2: Handle frustration signal (any state)
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_FRUSTRATION] ?? false) {
            return cognitive_state::FRUSTRATED;
        }

        // =====================================================================
        // PRIORITY 3: Handle closing signal (any state)
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_CLOSING] ?? false) {
            return cognitive_state::READY_TO_CLOSE;
        }

        // =====================================================================
        // PRIORITY 4: Handle answer request (academic integrity)
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_ANSWER_REQUEST] ?? false) {
            return cognitive_state::SEEKING_ANSWER;
        }

        // =====================================================================
        // STATE-SPECIFIC TRANSITIONS
        // =====================================================================
        switch ($state) {
            case cognitive_state::NEW_QUESTION:
                return $this->transition_from_new_question($signals);

            case cognitive_state::EXPLORING:
                return $this->transition_from_exploring($signals, $currentstate);

            case cognitive_state::BLOCKED:
                return $this->transition_from_blocked($signals, $currentstate);

            case cognitive_state::FRUSTRATED:
                return $this->transition_from_frustrated($signals);

            case cognitive_state::MAKING_PROGRESS:
                return $this->transition_from_making_progress($signals);

            case cognitive_state::SEEKING_ANSWER:
                return $this->transition_from_seeking_answer($signals);

            case cognitive_state::READY_TO_CLOSE:
                return $this->transition_from_ready_to_close($signals);

            case cognitive_state::NEEDS_ESCALATION:
                // Stay in escalation unless closing or new question.
                if ($signals[signal_detector::SIGNAL_CLOSING] ?? false) {
                    return cognitive_state::READY_TO_CLOSE;
                }
                if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
                    return cognitive_state::NEW_QUESTION;
                }
                return cognitive_state::NEEDS_ESCALATION;

            default:
                return cognitive_state::EXPLORING;
        }
    }

    /**
     * Check if escalation is needed.
     *
     * @param cognitive_state $state Current state.
     * @param int $attempts Guidance attempts.
     * @return bool True if should escalate.
     */
    private function should_escalate(cognitive_state $state, int $attempts): bool {
        // Too many guidance attempts.
        if ($attempts >= $this->maxattempts) {
            return true;
        }

        // Stuck in BLOCKED state too many times.
        if ($state->get_blocked_count() >= self::MAX_BLOCKED_BEFORE_ESCALATION) {
            return true;
        }

        return false;
    }

    /**
     * Transitions from NEW_QUESTION state.
     *
     * @param array $signals Detected signals.
     * @return string Next state.
     */
    private function transition_from_new_question(array $signals): string {
        // Student confirms understanding right away (simple question).
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // Student expresses confusion - they need help.
        if ($signals[signal_detector::SIGNAL_CONFUSION] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // Default: start exploring the topic.
        return cognitive_state::EXPLORING;
    }

    /**
     * Transitions from EXPLORING state.
     *
     * @param array $signals Detected signals.
     * @param cognitive_state $state Current state object.
     * @return string Next state.
     */
    private function transition_from_exploring(array $signals, cognitive_state $state): string {
        // Student confirms understanding.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // Student is attempting to engage/answer.
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // Uncertainty after exploring = moving toward blocked.
        if ($signals[signal_detector::SIGNAL_UNCERTAINTY] ?? false) {
            if ($state->get_uncertainty_count() >= self::MAX_UNCERTAINTY_BEFORE_BLOCKED) {
                return cognitive_state::BLOCKED;
            }
            return cognitive_state::EXPLORING; // Stay but track uncertainty.
        }

        // Explicit confusion = blocked.
        if ($signals[signal_detector::SIGNAL_CONFUSION] ?? false) {
            return cognitive_state::BLOCKED;
        }

        // Needs clarification = stay exploring but note it.
        if ($signals[signal_detector::SIGNAL_NEEDS_CLARIFICATION] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // New question = reset to new question.
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return cognitive_state::NEW_QUESTION;
        }

        // Default: stay exploring.
        return cognitive_state::EXPLORING;
    }

    /**
     * Transitions from BLOCKED state.
     *
     * @param array $signals Detected signals.
     * @param cognitive_state $state Current state object.
     * @return string Next state.
     */
    private function transition_from_blocked(array $signals, cognitive_state $state): string {
        // Any sign of understanding = making progress.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // Student attempting = making progress.
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // New question = fresh start.
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return cognitive_state::NEW_QUESTION;
        }

        // Still confused or uncertain = stay blocked.
        if (($signals[signal_detector::SIGNAL_CONFUSION] ?? false) ||
            ($signals[signal_detector::SIGNAL_UNCERTAINTY] ?? false)) {
            return cognitive_state::BLOCKED;
        }

        // Wants example = back to exploring with new approach.
        if ($signals[signal_detector::SIGNAL_WANTS_EXAMPLE] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // Default: stay blocked (will eventually escalate).
        return cognitive_state::BLOCKED;
    }

    /**
     * Transitions from FRUSTRATED state.
     *
     * @param array $signals Detected signals.
     * @return string Next state.
     */
    private function transition_from_frustrated(array $signals): string {
        // Understanding despite frustration = progress.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // New question = fresh start (frustration addressed).
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return cognitive_state::NEW_QUESTION;
        }

        // Attempting = moving forward.
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // Still frustrated = stay (agent should provide empathy).
        if ($signals[signal_detector::SIGNAL_FRUSTRATION] ?? false) {
            return cognitive_state::FRUSTRATED;
        }

        // Confusion without frustration = blocked.
        if ($signals[signal_detector::SIGNAL_CONFUSION] ?? false) {
            return cognitive_state::BLOCKED;
        }

        // Default: assume frustration acknowledged, move to exploring.
        return cognitive_state::EXPLORING;
    }

    /**
     * Transitions from MAKING_PROGRESS state.
     *
     * @param array $signals Detected signals.
     * @return string Next state.
     */
    private function transition_from_making_progress(array $signals): string {
        // Continued understanding.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return cognitive_state::MAKING_PROGRESS;
        }

        // New question = fresh start.
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return cognitive_state::NEW_QUESTION;
        }

        // Confusion = back to exploring.
        if ($signals[signal_detector::SIGNAL_CONFUSION] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // Uncertainty = back to exploring.
        if ($signals[signal_detector::SIGNAL_UNCERTAINTY] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // Default: stay making progress.
        return cognitive_state::MAKING_PROGRESS;
    }

    /**
     * Transitions from SEEKING_ANSWER state.
     *
     * @param array $signals Detected signals.
     * @return string Next state.
     */
    private function transition_from_seeking_answer(array $signals): string {
        // Student understands redirection.
        if ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // New question = fresh start.
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return cognitive_state::NEW_QUESTION;
        }

        // Still requesting answer = stay (policy will redirect again).
        if ($signals[signal_detector::SIGNAL_ANSWER_REQUEST] ?? false) {
            return cognitive_state::SEEKING_ANSWER;
        }

        // Attempting to engage = exploring.
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            return cognitive_state::EXPLORING;
        }

        // Default: move to exploring (assume redirection worked).
        return cognitive_state::EXPLORING;
    }

    /**
     * Transitions from READY_TO_CLOSE state.
     *
     * @param array $signals Detected signals.
     * @return string Next state.
     */
    private function transition_from_ready_to_close(array $signals): string {
        // New question = fresh start.
        if ($signals[signal_detector::SIGNAL_NEW_QUESTION] ?? false) {
            return cognitive_state::NEW_QUESTION;
        }

        // Any substantial engagement = back to exploring.
        if (($signals[signal_detector::SIGNAL_CONFUSION] ?? false) ||
            ($signals[signal_detector::SIGNAL_WANTS_EXAMPLE] ?? false)) {
            return cognitive_state::EXPLORING;
        }

        // Default: stay ready to close.
        return cognitive_state::READY_TO_CLOSE;
    }

    /**
     * Update cognitive state object based on signals.
     *
     * This also updates uncertainty counters.
     *
     * @param cognitive_state $state State to update.
     * @param array $signals Detected signals.
     * @param int $guidanceattempts Guidance attempt count.
     * @return void
     */
    public function apply_transition(
        cognitive_state $state,
        array $signals,
        int $guidanceattempts
    ): void {
        // Update uncertainty counter.
        if ($signals[signal_detector::SIGNAL_UNCERTAINTY] ?? false) {
            $state->increment_uncertainty();
        } elseif ($signals[signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING] ?? false) {
            $state->reset_uncertainty();
        }

        // Compute and apply new state.
        $newstate = $this->compute_next_state($state, $signals, $guidanceattempts);
        $state->transition_to($newstate);
    }

    // =========================================================================
    // COGNITIVE PHASE DETERMINATION
    // =========================================================================

    /**
     * Determine the cognitive phase based on signals and context.
     *
     * COGNITIVE PHASES determine what TYPE of pedagogical response is appropriate:
     * - NO_MENTAL_MODEL: Direct explanation only (no questions, no analogies)
     * - PARTIAL_MENTAL_MODEL: Explanation with optional questions
     * - FUNCTIONAL_MENTAL_MODEL: Full Socratic method available
     *
     * TRANSITION RULES:
     * - Any state → NO_MENTAL_MODEL: When LACKS_MENTAL_MODEL signal detected
     * - NO_MENTAL_MODEL → PARTIAL_MENTAL_MODEL: After explanation + attempting signal
     * - PARTIAL_MENTAL_MODEL → FUNCTIONAL_MENTAL_MODEL: After confirmed understanding
     * - FUNCTIONAL_MENTAL_MODEL → NO_MENTAL_MODEL: New topic or explicit lack of model
     *
     * @param array $signals Detected signals from signal_detector.
     * @param int $explanationattempts Number of explanation attempts for current topic.
     * @param bool $hasconfirmedunderstanding Whether student has confirmed understanding.
     * @param string $currentphase Current cognitive phase.
     * @return string New cognitive phase constant.
     */
    public function determine_cognitive_phase(
        array $signals,
        int $explanationattempts,
        bool $hasconfirmedunderstanding,
        string $currentphase = cognitive_state::PHASE_NO_MODEL
    ): string {

        // =====================================================================
        // PRIORITY 1: Explicit lack of mental model → Always NO_MENTAL_MODEL
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_LACKS_MENTAL_MODEL] ?? false) {
            return cognitive_state::PHASE_NO_MODEL;
        }

        // =====================================================================
        // PRIORITY 2: First interaction with topic → NO_MENTAL_MODEL
        // =====================================================================
        if ($explanationattempts === 0) {
            return cognitive_state::PHASE_NO_MODEL;
        }

        // =====================================================================
        // PRIORITY 3: Student confirmed understanding → FUNCTIONAL_MENTAL_MODEL
        // =====================================================================
        if ($hasconfirmedunderstanding) {
            return cognitive_state::PHASE_FUNCTIONAL_MODEL;
        }

        // =====================================================================
        // PRIORITY 4: Student wants to practice → FUNCTIONAL_MENTAL_MODEL
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_READY_TO_PRACTICE] ?? false) {
            return cognitive_state::PHASE_FUNCTIONAL_MODEL;
        }

        // =====================================================================
        // PRIORITY 5: Student is attempting to engage → PARTIAL_MENTAL_MODEL
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_ATTEMPTING] ?? false) {
            // Only upgrade to partial if we've given at least one explanation.
            if ($explanationattempts >= 1) {
                return cognitive_state::PHASE_PARTIAL_MODEL;
            }
        }

        // =====================================================================
        // PRIORITY 6: Confusion after explanation(s) → Check if model is forming
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_CONFUSION] ?? false) {
            // If still confused after multiple explanations, stay/return to NO_MODEL.
            if ($explanationattempts >= 2) {
                return cognitive_state::PHASE_NO_MODEL;
            }
            // Single confusion after first explanation = partial (needs more help).
            if ($explanationattempts === 1) {
                return cognitive_state::PHASE_PARTIAL_MODEL;
            }
        }

        // =====================================================================
        // PRIORITY 7: Uncertainty signals → Depends on current phase
        // =====================================================================
        if ($signals[signal_detector::SIGNAL_UNCERTAINTY] ?? false) {
            // Uncertainty doesn't regress phase, but doesn't advance it either.
            if ($currentphase === cognitive_state::PHASE_FUNCTIONAL_MODEL) {
                // Slight uncertainty in functional phase = stay functional.
                return cognitive_state::PHASE_FUNCTIONAL_MODEL;
            }
            // Otherwise, stay in current or go to partial.
            return $explanationattempts >= 1 ? cognitive_state::PHASE_PARTIAL_MODEL : cognitive_state::PHASE_NO_MODEL;
        }

        // =====================================================================
        // DEFAULT: Maintain current phase or advance based on explanation count
        // =====================================================================
        if ($currentphase === cognitive_state::PHASE_FUNCTIONAL_MODEL) {
            return cognitive_state::PHASE_FUNCTIONAL_MODEL;
        }

        if ($explanationattempts >= 1 && $currentphase !== cognitive_state::PHASE_NO_MODEL) {
            return cognitive_state::PHASE_PARTIAL_MODEL;
        }

        // Default: NO_MENTAL_MODEL (safest assumption).
        return cognitive_state::PHASE_NO_MODEL;
    }

    /**
     * Apply both cognitive phase and state transitions.
     *
     * Combines phase determination with state transition in a single call.
     *
     * @param cognitive_state $state State object to update.
     * @param array $signals Detected signals.
     * @param int $guidanceattempts Guidance attempt count.
     * @param int $explanationattempts Explanation attempt count.
     * @param bool $hasconfirmedunderstanding Whether understanding was confirmed.
     * @return void
     */
    public function apply_full_transition(
        cognitive_state $state,
        array $signals,
        int $guidanceattempts,
        int $explanationattempts,
        bool $hasconfirmedunderstanding
    ): void {
        // First, determine cognitive phase.
        $currentphase = $state->get_cognitive_phase();
        $newphase = $this->determine_cognitive_phase(
            $signals,
            $explanationattempts,
            $hasconfirmedunderstanding,
            $currentphase
        );
        $state->set_cognitive_phase($newphase);

        // Then, apply state transition.
        $this->apply_transition($state, $signals, $guidanceattempts);
    }
}
