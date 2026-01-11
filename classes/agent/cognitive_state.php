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
 * Cognitive states representing the student's learning condition.
 *
 * These states represent WHERE the student is in their learning journey,
 * NOT what words they used. States drive action selection through the
 * central policy function.
 *
 * COGNITIVE PHASES (NEW):
 * The agent now uses a two-tier system:
 * 1. Cognitive Phase: Determines WHAT TYPE of response (explanation vs exploration)
 * 2. Cognitive State: Determines emotional/situational context (frustrated, blocked, etc.)
 *
 * Phases are:
 * - NO_MENTAL_MODEL: Student has no foundational understanding. Direct explanation only.
 * - PARTIAL_MENTAL_MODEL: Student has basic idea but incomplete. Optional questions.
 * - FUNCTIONAL_MENTAL_MODEL: Student can reason. Full Socratic method available.
 *
 * State transitions are determined by:
 * - Detected signals (from regex/heuristics)
 * - Previous state
 * - Guidance attempt count
 * - Conversation history patterns
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cognitive_state {

    // =========================================================================
    // COGNITIVE PHASES - Determine type of pedagogical response
    // =========================================================================

    /**
     * Student has NO mental model of the concept.
     * Requires direct, literal explanation without questions or analogies.
     */
    public const PHASE_NO_MODEL = 'no_mental_model';

    /**
     * Student has a PARTIAL mental model.
     * Can handle simple questions and analogies. Verification optional.
     */
    public const PHASE_PARTIAL_MODEL = 'partial_mental_model';

    /**
     * Student has a FUNCTIONAL mental model.
     * Can benefit from Socratic method, practice, and exploration.
     */
    public const PHASE_FUNCTIONAL_MODEL = 'functional_mental_model';

    /**
     * Valid cognitive phases.
     */
    private const VALID_PHASES = [
        self::PHASE_NO_MODEL,
        self::PHASE_PARTIAL_MODEL,
        self::PHASE_FUNCTIONAL_MODEL,
    ];

    // =========================================================================
    // COGNITIVE STATES - Finite set representing student learning condition
    // =========================================================================

    /**
     * Student has asked a new question or started a new topic.
     * Entry state for new learning interactions.
     */
    public const NEW_QUESTION = 'new_question';

    /**
     * Student is actively exploring the concept.
     * Engaged but still building understanding.
     */
    public const EXPLORING = 'exploring';

    /**
     * Student is stuck and not making progress.
     * May need scaffolding or different approach.
     */
    public const BLOCKED = 'blocked';

    /**
     * Student is expressing frustration or emotional difficulty.
     * Requires empathetic response before continuing.
     */
    public const FRUSTRATED = 'frustrated';

    /**
     * Student shows signs of understanding.
     * Making progress, can continue with guidance.
     */
    public const MAKING_PROGRESS = 'making_progress';

    /**
     * Student has indicated understanding or wants to close.
     * Ready to wrap up or move to new topic.
     */
    public const READY_TO_CLOSE = 'ready_to_close';

    /**
     * Student is requesting direct answers (integrity concern).
     * Needs redirection to learning-focused approach.
     */
    public const SEEKING_ANSWER = 'seeking_answer';

    /**
     * Multiple attempts failed, escalation needed.
     * Should suggest teacher/tutor assistance.
     */
    public const NEEDS_ESCALATION = 'needs_escalation';

    /**
     * All valid cognitive states.
     */
    private const VALID_STATES = [
        self::NEW_QUESTION,
        self::EXPLORING,
        self::BLOCKED,
        self::FRUSTRATED,
        self::MAKING_PROGRESS,
        self::READY_TO_CLOSE,
        self::SEEKING_ANSWER,
        self::NEEDS_ESCALATION,
    ];

    /** @var string Current state. */
    private string $state;

    /** @var string Previous state (for transition tracking). */
    private string $previousstate;

    /** @var int Consecutive times in BLOCKED state. */
    private int $blockedcount = 0;

    /** @var int Consecutive uncertainty signals. */
    private int $uncertaintycount = 0;

    /** @var string Current cognitive phase. */
    private string $cognitivephase = self::PHASE_NO_MODEL;

    /**
     * Constructor.
     *
     * @param string $initialstate Initial cognitive state.
     */
    public function __construct(string $initialstate = self::NEW_QUESTION) {
        $this->state = $this->validate_state($initialstate);
        $this->previousstate = $initialstate;
    }

    /**
     * Get the current cognitive state.
     *
     * @return string Current state constant.
     */
    public function get_state(): string {
        return $this->state;
    }

    /**
     * Get the previous cognitive state.
     *
     * @return string Previous state constant.
     */
    public function get_previous_state(): string {
        return $this->previousstate;
    }

    /**
     * Transition to a new state.
     *
     * @param string $newstate The new state.
     * @return void
     */
    public function transition_to(string $newstate): void {
        $validated = $this->validate_state($newstate);
        $this->previousstate = $this->state;
        $this->state = $validated;

        // Track consecutive blocked states.
        if ($validated === self::BLOCKED) {
            $this->blockedcount++;
        } else {
            $this->blockedcount = 0;
        }
    }

    /**
     * Increment uncertainty counter.
     *
     * Called when student shows continued uncertainty.
     *
     * @return void
     */
    public function increment_uncertainty(): void {
        $this->uncertaintycount++;
    }

    /**
     * Reset uncertainty counter.
     *
     * Called when student shows progress.
     *
     * @return void
     */
    public function reset_uncertainty(): void {
        $this->uncertaintycount = 0;
    }

    /**
     * Get consecutive blocked count.
     *
     * @return int Number of times consecutively in BLOCKED state.
     */
    public function get_blocked_count(): int {
        return $this->blockedcount;
    }

    /**
     * Get uncertainty count.
     *
     * @return int Number of consecutive uncertainty signals.
     */
    public function get_uncertainty_count(): int {
        return $this->uncertaintycount;
    }

    /**
     * Check if student is in a "stuck" condition.
     *
     * @return bool True if blocked multiple times or high uncertainty.
     */
    public function is_stuck(): bool {
        return $this->blockedcount >= 2 || $this->uncertaintycount >= 3;
    }

    /**
     * Check if state requires empathetic response.
     *
     * @return bool True if frustrated or repeatedly blocked.
     */
    public function needs_empathy(): bool {
        return $this->state === self::FRUSTRATED || $this->blockedcount >= 3;
    }

    /**
     * Check if this is a positive/progress state.
     *
     * @return bool True if making progress or ready to close.
     */
    public function is_positive(): bool {
        return in_array($this->state, [self::MAKING_PROGRESS, self::READY_TO_CLOSE]);
    }

    // =========================================================================
    // COGNITIVE PHASE METHODS
    // =========================================================================

    /**
     * Get the current cognitive phase.
     *
     * @return string Current phase constant.
     */
    public function get_cognitive_phase(): string {
        return $this->cognitivephase;
    }

    /**
     * Set the cognitive phase.
     *
     * @param string $phase The new phase.
     * @return void
     * @throws \InvalidArgumentException If phase is invalid.
     */
    public function set_cognitive_phase(string $phase): void {
        if (!in_array($phase, self::VALID_PHASES)) {
            throw new \InvalidArgumentException("Invalid cognitive phase: {$phase}");
        }
        $this->cognitivephase = $phase;
    }

    /**
     * Check if exploration is allowed in current phase.
     *
     * Only FUNCTIONAL_MENTAL_MODEL phase allows full exploration
     * with Socratic questions and practice problems.
     *
     * @return bool True if student can engage in exploration.
     */
    public function can_explore(): bool {
        return $this->cognitivephase === self::PHASE_FUNCTIONAL_MODEL;
    }

    /**
     * Check if questions are allowed in current phase.
     *
     * Questions are:
     * - PROHIBITED in NO_MENTAL_MODEL (direct explanation only)
     * - OPTIONAL in PARTIAL_MENTAL_MODEL
     * - ENABLED in FUNCTIONAL_MENTAL_MODEL
     *
     * @return bool True if questions can be used.
     */
    public function can_ask_questions(): bool {
        return $this->cognitivephase !== self::PHASE_NO_MODEL;
    }

    /**
     * Check if questions are mandatory in current phase.
     *
     * Only FUNCTIONAL_MENTAL_MODEL uses mandatory questions.
     *
     * @return bool True if questions should always be included.
     */
    public function requires_questions(): bool {
        return $this->cognitivephase === self::PHASE_FUNCTIONAL_MODEL;
    }

    /**
     * Check if direct explanation is required in current phase.
     *
     * NO_MENTAL_MODEL phase requires direct, literal explanation
     * without questions, analogies, or verification requests.
     *
     * @return bool True if direct explanation mode is required.
     */
    public function requires_direct_explanation(): bool {
        return $this->cognitivephase === self::PHASE_NO_MODEL;
    }

    /**
     * Check if analogies are allowed in current phase.
     *
     * Analogies are:
     * - PROHIBITED in NO_MENTAL_MODEL (adds cognitive load)
     * - OPTIONAL in PARTIAL_MENTAL_MODEL
     * - ENABLED in FUNCTIONAL_MENTAL_MODEL
     *
     * @return bool True if analogies can be used.
     */
    public function can_use_analogies(): bool {
        return $this->cognitivephase !== self::PHASE_NO_MODEL;
    }

    /**
     * Validate a state value.
     *
     * @param string $state State to validate.
     * @return string Validated state.
     * @throws \InvalidArgumentException If state is invalid.
     */
    private function validate_state(string $state): string {
        if (!in_array($state, self::VALID_STATES)) {
            throw new \InvalidArgumentException("Invalid cognitive state: {$state}");
        }
        return $state;
    }

    /**
     * Create state from agent_memory state.
     *
     * Maps legacy memory states to cognitive states.
     *
     * @param string $memorystate State from agent_memory.
     * @return self New cognitive_state instance.
     */
    public static function from_memory_state(string $memorystate): self {
        $mapping = [
            agent_memory::STATE_NEW => self::NEW_QUESTION,
            agent_memory::STATE_UNDERSTANDING => self::EXPLORING,
            agent_memory::STATE_GUIDING => self::EXPLORING,
            agent_memory::STATE_CHECKING => self::MAKING_PROGRESS,
            agent_memory::STATE_ESCALATING => self::NEEDS_ESCALATION,
            agent_memory::STATE_COMPLETED => self::READY_TO_CLOSE,
        ];

        $initialstate = $mapping[$memorystate] ?? self::NEW_QUESTION;
        return new self($initialstate);
    }

    /**
     * Export state to array for persistence.
     *
     * @return array State data.
     */
    public function to_array(): array {
        return [
            'state' => $this->state,
            'previous_state' => $this->previousstate,
            'blocked_count' => $this->blockedcount,
            'uncertainty_count' => $this->uncertaintycount,
            'cognitive_phase' => $this->cognitivephase,
        ];
    }

    /**
     * Restore state from array.
     *
     * @param array $data State data.
     * @return self Restored instance.
     */
    public static function from_array(array $data): self {
        $instance = new self($data['state'] ?? self::NEW_QUESTION);
        $instance->previousstate = $data['previous_state'] ?? $instance->state;
        $instance->blockedcount = $data['blocked_count'] ?? 0;
        $instance->uncertaintycount = $data['uncertainty_count'] ?? 0;
        $instance->cognitivephase = $data['cognitive_phase'] ?? self::PHASE_NO_MODEL;
        return $instance;
    }
}
