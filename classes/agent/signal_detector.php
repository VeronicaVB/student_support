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
 * Hybrid signal detector for student messages.
 *
 * ARCHITECTURE: Hybrid regex + LLM approach
 * - Regex for CLEAR signals: answer_request, closing, frustration (distinct patterns)
 * - LLM for AMBIGUOUS signals: confusion vs uncertainty, ready_to_practice, etc.
 *
 * This approach balances:
 * - Speed: Regex is instant for clear cases
 * - Accuracy: LLM handles nuanced/ambiguous messages
 * - Cost: LLM only called when needed
 *
 * Signals feed into:
 * 1. State transition engine (to update cognitive state)
 * 2. Action policy (as additional context for decision)
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signal_detector {

    /** @var llm_signal_classifier|null LLM classifier for ambiguous cases. */
    private ?llm_signal_classifier $llmclassifier = null;

    /** @var bool Whether to use LLM for ambiguous signals. */
    private bool $usellm = true;

    // =========================================================================
    // SIGNAL TYPES - Boolean flags, NOT action selectors
    // =========================================================================

    /** Student is asking for direct answer (academic integrity concern). */
    public const SIGNAL_ANSWER_REQUEST = 'answer_request';

    /** Student expresses confusion or lack of understanding. */
    public const SIGNAL_CONFUSION = 'confusion';

    /** Student expresses frustration or negative emotion. */
    public const SIGNAL_FRUSTRATION = 'frustration';

    /** Student wants an example. */
    public const SIGNAL_WANTS_EXAMPLE = 'wants_example';

    /** Student confirms or indicates understanding. */
    public const SIGNAL_CONFIRMS_UNDERSTANDING = 'confirms_understanding';

    /** Student wants to end or thanks. */
    public const SIGNAL_CLOSING = 'closing';

    /** Student asks a new/different question. */
    public const SIGNAL_NEW_QUESTION = 'new_question';

    /** Student gives uncertain response (I'm not sure, maybe, etc.). */
    public const SIGNAL_UNCERTAINTY = 'uncertainty';

    /** Student requests clarification of previous response. */
    public const SIGNAL_NEEDS_CLARIFICATION = 'needs_clarification';

    /** Student is attempting to answer/engage. */
    public const SIGNAL_ATTEMPTING = 'attempting';

    /** Student wants to practice/try something (ready to apply learning). */
    public const SIGNAL_READY_TO_PRACTICE = 'ready_to_practice';

    /**
     * Student has NO mental model of the concept.
     *
     * This signal indicates the student cannot reason about the concept
     * because they lack foundational understanding. They need direct,
     * literal explanation WITHOUT questions, analogies, or exploration.
     *
     * Triggered by:
     * - Explicit statements: "I don't understand anything", "What is that?"
     * - Requests to start from scratch: "Explain from the beginning"
     * - Inability to reformulate: vague/circular responses after explanation
     * - First interaction with a completely new topic
     */
    public const SIGNAL_LACKS_MENTAL_MODEL = 'lacks_mental_model';

    // =========================================================================
    // REGEX PATTERNS - Grouped by signal type
    // =========================================================================

    /** @var array Patterns for SIGNAL_ANSWER_REQUEST. */
    private const ANSWER_REQUEST_PATTERNS = [
        '/\b(give\s+me|tell\s+me|what\s+is)\s+(the\s+)?answer/i',
        '/\b(just\s+)?(tell|give)\s+me\s+(the\s+)?(solution|answer|result)/i',
        '/\bwhat\'?s?\s+the\s+(correct|right)\s+answer/i',
        '/\bsolve\s+(this|it)\s+for\s+me/i',
        '/\bdo\s+(this|it|my\s+homework)\s+for\s+me/i',
        '/\bwrite\s+(this|it|the\s+essay|my\s+essay)\s+for\s+me/i',
        '/\bcomplete\s+(this|it)\s+for\s+me/i',
        '/\bjust\s+(give|show)\s+me\s+the\s+(code|solution|answer)/i',
        '/\bfinish\s+(this|it)\s+for\s+me/i',
    ];

    /** @var array Patterns for SIGNAL_CONFUSION. */
    private const CONFUSION_PATTERNS = [
        '/\b(i\s+)?(don\'?t|do\s+not)\s+understand/i',
        '/\b(i\'?m|i\s+am)\s+(confused|stuck|lost)/i',
        '/\bi\s+need\s+help/i',
        '/\bwhat\s+does\s+(this|that|it)\s+mean/i',
        '/\bi\'?m\s+having\s+trouble/i',
        '/\bthis\s+(doesn\'t|does\s+not)\s+make\s+sense/i',
        '/\bi\s+can\'?t\s+(figure|work)\s+(this|it)\s+out/i',
        '/\bwhat\s+do\s+you\s+mean/i',
        '/\bi\s+still\s+don\'?t\s+(get|understand)/i',
    ];

    /** @var array Patterns for SIGNAL_FRUSTRATION. */
    private const FRUSTRATION_PATTERNS = [
        '/\bthis\s+(is\s+)?(so\s+)?(frustrating|annoying|stupid|dumb)/i',
        '/\bi\s+(give\s+up|quit|can\'?t\s+do\s+(this|it))/i',
        '/\b(this|it)\s+makes\s+no\s+sense/i',
        '/\bi\'?m\s+(so\s+)?(frustrated|annoyed|angry)/i',
        '/\bwhy\s+(is\s+)?(this|it)\s+so\s+(hard|difficult|confusing)/i',
        '/\bi\s+hate\s+(this|it)/i',
        '/\bthis\s+is\s+impossible/i',
        '/\bforget\s+it/i',
        '/\bugh+/i',
    ];

    /** @var array Patterns for SIGNAL_WANTS_EXAMPLE. */
    private const WANTS_EXAMPLE_PATTERNS = [
        '/\b(give|show)\s+(me\s+)?(an?\s+)?example/i',
        '/\bfor\s+example/i',
        '/\bcan\s+you\s+(give|show)\s+(me\s+)?(an?\s+)?example/i',
        '/\blike\s+what/i',
        '/\bwhat\s+would\s+(this|that|it)\s+look\s+like/i',
        '/\bshow\s+me\s+how/i',
        '/\bcan\s+you\s+demonstrate/i',
    ];

    /** @var array Patterns for SIGNAL_CONFIRMS_UNDERSTANDING. */
    private const CONFIRMS_UNDERSTANDING_PATTERNS = [
        '/\b(i\s+)(think\s+i\s+)?(understand|get\s+it|see)\s*(now)?/i',
        '/\b(oh|ah),?\s+(i\s+)?(see|get\s+it|understand)/i',
        '/\bthat\s+makes\s+sense/i',
        '/\bgot\s+it/i',
        '/\bi\s+see\s+what\s+you\s+mean/i',
        '/\bnow\s+i\s+(understand|get\s+it)/i',
        '/\bthat\'?s\s+clear(er)?(\s+now)?/i',
        '/\byes,?\s+(i\s+)?(understand|see|get\s+it)/i',
    ];

    /** @var array Patterns for SIGNAL_CLOSING. */
    private const CLOSING_PATTERNS = [
        '/\b(thanks?|thank\s+you)(\s+for\s+(your\s+)?help)?[.!]?$/i',
        '/\b(bye|goodbye|see\s+you|later)/i',
        '/\bthat\'?s\s+all\s+(i\s+needed|for\s+now)/i',
        '/\bi\'?m\s+(good|done|fine)\s*(now|for\s+now)?/i',
        '/\bno\s+more\s+questions/i',
        '/\bthat\'?s\s+(it|everything)/i',
    ];

    /** @var array Patterns for SIGNAL_UNCERTAINTY. */
    private const UNCERTAINTY_PATTERNS = [
        '/\bi\'?m\s+not\s+sure/i',
        '/\bmaybe\b/i',
        '/\bi\s+(think|guess)\s+so/i',
        '/\bi\s+don\'?t\s+know/i',
        '/\bpossibly/i',
        '/\bperhaps/i',
        '/\bnot\s+really\s+sure/i',
        '/^\s*(i\s+)?don\'?t\s+know\s*[.?]?\s*$/i',
        '/^(um+|uh+|hmm+)/i',
    ];

    /** @var array Patterns for SIGNAL_NEEDS_CLARIFICATION. */
    private const CLARIFICATION_PATTERNS = [
        '/\bcan\s+you\s+(clarify|explain\s+that|be\s+more\s+specific)/i',
        '/\bi\'?m\s+not\s+sure\s+(i\s+)?understand/i',
        '/\bcould\s+you\s+(rephrase|say\s+that\s+again|explain\s+differently)/i',
        '/\bin\s+other\s+words/i',
        '/\bwhat\s+does\s+that\s+mean/i',
        '/\bcan\s+you\s+say\s+that\s+(again|differently)/i',
        '/\bsorry,?\s+(but\s+)?what/i',
        // Pushback/correction patterns - student correcting tutor's statement.
        '/\byou\s+(just\s+)?(came\s+up\s+with|said|mentioned|brought\s+up)/i',
        '/\bthat\'?s\s+(your|what\s+you)/i',
        '/\bthat\'?s\s+not\s+what\s+i\s+(asked|said|meant)/i',
        '/\bi\s+didn\'?t\s+(say|ask|mean)\s+(that|it)/i',
        '/\byou\'?re\s+(the\s+one|explaining)/i',
        '/\bi\s+never\s+(said|asked|mentioned)/i',
        '/\bthat\s+was\s+your\s+(idea|example|analogy)/i',
        '/\bwhy\s+are\s+you\s+(asking|saying|talking\s+about)/i',
    ];

    /** @var array Patterns for SIGNAL_NEW_QUESTION. */
    private const NEW_QUESTION_PATTERNS = [
        '/^(what|how|why|when|where|who|can|could|would|is|are|do|does)\s+/i',
        '/\bexplain\s+(to\s+me\s+)?(what|how|why)/i',
        '/\bhelp\s+me\s+(understand|with|figure)/i',
        '/\bcan\s+you\s+(help|explain|tell\s+me)/i',
        '/\bi\s+have\s+a\s+question/i',
        '/\bi\s+want\s+to\s+know/i',
    ];

    /** @var array Patterns for SIGNAL_ATTEMPTING (student trying to engage/answer). */
    private const ATTEMPTING_PATTERNS = [
        '/\bi\s+think\s+(it\'?s|the\s+answer\s+is|that)/i',
        '/\bso\s+(basically|it\'?s\s+like|you\'?re\s+saying)/i',
        '/\bis\s+it\s+(because|that|when)/i',
        '/\bwould\s+it\s+be/i',
        '/\blet\s+me\s+try/i',
        '/\bso\s+if\s+i/i',
        '/\bmaybe\s+it\'?s/i',
    ];

    /** @var array Patterns for SIGNAL_READY_TO_PRACTICE. */
    private const READY_TO_PRACTICE_PATTERNS = [
        '/\b(yes|yeah|yep|sure),?\s*(i\'?d?\s+)?(like|want)\s+to\s+try/i',
        '/\bi\'?d?\s+(like|want)\s+to\s+try\s+(an?\s+)?example/i',
        '/\blet\'?s?\s+try\s+(an?\s+)?example/i',
        '/\bcan\s+(i|we)\s+try\s+(one|an?\s+example|it)/i',
        '/\bi\'?m\s+ready\s+to\s+try/i',
        '/\blet\s+me\s+practice/i',
        '/\bcan\s+i\s+practice/i',
        '/\bi\s+want\s+to\s+practice/i',
        '/\bgive\s+me\s+(a\s+)?problem\s+to\s+(try|solve)/i',
        '/\byes,?\s+i\'?d?\s+like\s+to\s+try/i',
    ];

    /**
     * @var array Patterns for SIGNAL_LACKS_MENTAL_MODEL.
     *
     * These patterns indicate the student has NO foundational understanding
     * and cannot reason about the concept. Requires direct explanation.
     */
    private const LACKS_MENTAL_MODEL_PATTERNS = [
        // Explicit total confusion (English).
        '/\bi\s+(don\'?t|do\s+not)\s+understand\s+(anything|at\s+all|any\s+of\s+(this|it))/i',
        '/\bi\s+have\s+no\s+(idea|clue)\s+(what|about)/i',
        '/\bi\s+don\'?t\s+(even\s+)?know\s+where\s+to\s+(start|begin)/i',
        '/\bwhat\s+(is|are)\s+(that|this|those|these)\??$/i',
        '/\bwhat\s+does\s+(that|this)\s+(even\s+)?mean\??$/i',
        '/\bwhat\s+(is|are)\s+\w+\??$/i', // "What is recursion?" "What are fractions?"
        '/\bi\'?ve?\s+never\s+(heard|seen|learned)\s+(of|about)\s+(this|that|it)/i',
        '/\bthis\s+is\s+(all|completely)\s+(new|foreign)\s+to\s+me/i',
        '/\bi\s+don\'?t\s+(get|understand)\s+any\s+of\s+(this|it)/i',
        '/\beverything\s+(is\s+)?confus(ing|es\s+me)/i',
        '/\bnothing\s+makes\s+sense/i',

        // Requests to start from beginning (English).
        '/\b(can\s+you\s+)?(start|explain)\s+(from\s+)?(the\s+)?(beginning|scratch|zero|start)/i',
        '/\b(go\s+)?back\s+to\s+(the\s+)?(basics?|beginning|start)/i',
        '/\bfrom\s+the\s+(very\s+)?beginning/i',
        '/\bstart\s+over/i',
        '/\bexplain\s+(it\s+)?(like|as\s+if)\s+i\'?m?\s+(a\s+)?(five|5|beginner|new)/i',
        '/\bpretend\s+i\s+(know|understand)\s+nothing/i',
        
    ];

    /**
     * Constructor.
     *
     * @param bool $usellm Whether to use LLM for ambiguous signals.
     */
    public function __construct(bool $usellm = true) {
        $this->usellm = $usellm;
    }

    /**
     * Get or create the LLM classifier (lazy initialization).
     *
     * @return llm_signal_classifier The classifier instance.
     */
    private function get_llm_classifier(): llm_signal_classifier {
        if ($this->llmclassifier === null) {
            $this->llmclassifier = new llm_signal_classifier();
        }
        return $this->llmclassifier;
    }

    /**
     * Detect all signals in a message using HYBRID approach.
     *
     * STRATEGY:
     * 1. Use regex for CLEAR signals (answer_request, closing, frustration)
     * 2. For ambiguous/short messages, use LLM classification
     * 3. Apply context-based heuristics as final layer
     *
     * @param string $message The student message.
     * @param array $context Optional context for additional detection.
     * @return array Associative array of signal => bool.
     */
    public function detect(string $message, array $context = []): array {
        $signals = $this->get_empty_signals();

        $message = trim($message);
        if (empty($message)) {
            return $signals;
        }

        // =====================================================================
        // PHASE 1: Regex for CLEAR signals (high-confidence patterns)
        // These signals have distinctive, unambiguous patterns.
        // =====================================================================

        $signals[self::SIGNAL_ANSWER_REQUEST] = $this->matches_patterns($message, self::ANSWER_REQUEST_PATTERNS);
        $signals[self::SIGNAL_FRUSTRATION] = $this->matches_patterns($message, self::FRUSTRATION_PATTERNS);
        $signals[self::SIGNAL_CLOSING] = $this->matches_patterns($message, self::CLOSING_PATTERNS);

        // CRITICAL: Check for lack of mental model EARLY - this drives cognitive phase.
        $signals[self::SIGNAL_LACKS_MENTAL_MODEL] = $this->detect_lacks_mental_model($message, $context);

        // If we have a clear signal, we can return early (these are high-priority).
        if ($signals[self::SIGNAL_ANSWER_REQUEST] || $signals[self::SIGNAL_FRUSTRATION] || $signals[self::SIGNAL_CLOSING]) {
            return $signals;
        }

        // If lacks mental model is detected, also set confusion (they overlap but have different purposes).
        if ($signals[self::SIGNAL_LACKS_MENTAL_MODEL]) {
            $signals[self::SIGNAL_CONFUSION] = true;
        }

        // =====================================================================
        // PHASE 2: Check if message is AMBIGUOUS and needs LLM
        // Short messages or messages without clear patterns need LLM help.
        // =====================================================================

        $isambiguous = $this->is_ambiguous_message($message, $context);

        if ($isambiguous && $this->usellm) {
            $signals = $this->classify_with_llm($message, $context, $signals);
        } else {
            // Use regex for all signals (fallback when LLM disabled or message is clear).
            $signals = $this->detect_all_with_regex($message, $signals);
        }

        // =====================================================================
        // PHASE 3: Apply context-based heuristics
        // These refine signals based on conversation history.
        // =====================================================================

        $signals = $this->apply_heuristics($message, $signals, $context);

        return $signals;
    }

    /**
     * Get an empty signals array.
     *
     * @return array All signals set to false.
     */
    private function get_empty_signals(): array {
        return [
            self::SIGNAL_ANSWER_REQUEST => false,
            self::SIGNAL_CONFUSION => false,
            self::SIGNAL_FRUSTRATION => false,
            self::SIGNAL_WANTS_EXAMPLE => false,
            self::SIGNAL_CONFIRMS_UNDERSTANDING => false,
            self::SIGNAL_CLOSING => false,
            self::SIGNAL_NEW_QUESTION => false,
            self::SIGNAL_UNCERTAINTY => false,
            self::SIGNAL_NEEDS_CLARIFICATION => false,
            self::SIGNAL_ATTEMPTING => false,
            self::SIGNAL_READY_TO_PRACTICE => false,
            self::SIGNAL_LACKS_MENTAL_MODEL => false,
        ];
    }

    /**
     * Check if a message is ambiguous and needs LLM classification.
     *
     * @param string $message The message.
     * @param array $context Conversation context.
     * @return bool True if message is ambiguous.
     */
    private function is_ambiguous_message(string $message, array $context): bool {
        $wordcount = str_word_count($message);

        // Short messages are often ambiguous (yes, no, everything, ok, etc.).
        if ($wordcount <= 5) {
            return true;
        }

        // Messages without clear pattern matches are ambiguous.
        $hasanymatch = false;
        $allpatterns = [
            self::CONFUSION_PATTERNS,
            self::WANTS_EXAMPLE_PATTERNS,
            self::CONFIRMS_UNDERSTANDING_PATTERNS,
            self::UNCERTAINTY_PATTERNS,
            self::CLARIFICATION_PATTERNS,
            self::NEW_QUESTION_PATTERNS,
            self::ATTEMPTING_PATTERNS,
            self::READY_TO_PRACTICE_PATTERNS,
        ];

        foreach ($allpatterns as $patterns) {
            if ($this->matches_patterns($message, $patterns)) {
                $hasanymatch = true;
                break;
            }
        }

        // If no regex matched but message exists, it's ambiguous.
        return !$hasanymatch;
    }

    /**
     * Classify signals using LLM.
     *
     * @param string $message The message.
     * @param array $context Conversation context.
     * @param array $signals Current signals array.
     * @return array Updated signals.
     */
    private function classify_with_llm(string $message, array $context, array $signals): array {
        $classifier = $this->get_llm_classifier();

        if (!$classifier->is_available()) {
            // Fallback to regex if LLM unavailable.
            return $this->detect_all_with_regex($message, $signals);
        }

        $classification = $classifier->classify($message, $context);

        // Only apply LLM result if confidence is reasonable.
        if ($classification['confidence'] >= 0.5) {
            $llmsignal = llm_signal_classifier::translate_to_detector_signal($classification['primary_signal']);

            if (!empty($llmsignal) && isset($signals[$llmsignal])) {
                $signals[$llmsignal] = true;
            }
        } else {
            // Low confidence - fall back to regex.
            $signals = $this->detect_all_with_regex($message, $signals);
        }

        return $signals;
    }

    /**
     * Detect all signals using regex (fallback method).
     *
     * @param string $message The message.
     * @param array $signals Current signals array.
     * @return array Updated signals.
     */
    private function detect_all_with_regex(string $message, array $signals): array {
        $signals[self::SIGNAL_CONFUSION] = $this->matches_patterns($message, self::CONFUSION_PATTERNS);
        $signals[self::SIGNAL_WANTS_EXAMPLE] = $this->matches_patterns($message, self::WANTS_EXAMPLE_PATTERNS);
        $signals[self::SIGNAL_CONFIRMS_UNDERSTANDING] = $this->matches_patterns($message, self::CONFIRMS_UNDERSTANDING_PATTERNS);
        $signals[self::SIGNAL_UNCERTAINTY] = $this->matches_patterns($message, self::UNCERTAINTY_PATTERNS);
        $signals[self::SIGNAL_NEEDS_CLARIFICATION] = $this->matches_patterns($message, self::CLARIFICATION_PATTERNS);
        $signals[self::SIGNAL_NEW_QUESTION] = $this->matches_patterns($message, self::NEW_QUESTION_PATTERNS);
        $signals[self::SIGNAL_ATTEMPTING] = $this->matches_patterns($message, self::ATTEMPTING_PATTERNS);
        $signals[self::SIGNAL_READY_TO_PRACTICE] = $this->matches_patterns($message, self::READY_TO_PRACTICE_PATTERNS);

        return $signals;
    }

    /**
     * Check if message matches any patterns in a group.
     *
     * @param string $message The message.
     * @param array $patterns Array of regex patterns.
     * @return bool True if any pattern matches.
     */
    private function matches_patterns(string $message, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply heuristics for edge cases not covered by regex.
     *
     * @param string $message The message.
     * @param array $signals Current signals.
     * @param array $context Conversation context.
     * @return array Updated signals.
     */
    private function apply_heuristics(string $message, array $signals, array $context): array {
        $wordcount = str_word_count($message);
        $previousaction = $context['last_action'] ?? '';
        $lowermessage = strtolower(trim($message));

        // =====================================================================
        // PUSHBACK/CORRECTION DETECTION
        // When student is correcting the tutor, prioritize needs_clarification
        // over confusion, even if "what do you mean" is in the message.
        // =====================================================================

        $ispushback = $this->is_pushback_message($lowermessage);
        if ($ispushback) {
            $signals[self::SIGNAL_NEEDS_CLARIFICATION] = true;
            $signals[self::SIGNAL_CONFUSION] = false; // Not confusion, it's correction.
            $signals[self::SIGNAL_NEW_QUESTION] = false; // Not a new topic.
            return $signals;
        }

        // =====================================================================
        // AFFIRMATIVE RESPONSE DETECTION
        // Short affirmative responses like "yes", "yes," should be interpreted
        // based on context, not as uncertainty.
        // =====================================================================

        $isaffirmative = preg_match('/^(yes|yeah|yep|sure|ok|okay|yup|uh-huh)[,.\s!]*$/i', $lowermessage);

        if ($isaffirmative) {
            // If agent asked about trying an example, "yes" = ready to practice.
            if ($this->agent_asked_about_trying($context)) {
                $signals[self::SIGNAL_READY_TO_PRACTICE] = true;
                $signals[self::SIGNAL_CONFIRMS_UNDERSTANDING] = true;
                // Remove uncertainty if it was set.
                $signals[self::SIGNAL_UNCERTAINTY] = false;
                return $signals;
            }

            // General "yes" after guidance = confirms understanding.
            if (in_array($previousaction, ['ask_guiding_question', 'explain_concept', 'give_example'])) {
                $signals[self::SIGNAL_CONFIRMS_UNDERSTANDING] = true;
                $signals[self::SIGNAL_UNCERTAINTY] = false;
                return $signals;
            }
        }

        // =====================================================================
        // SHORT RESPONSE HANDLING
        // =====================================================================

        // Short response after guiding question that's NOT affirmative = likely uncertainty.
        if ($wordcount <= 3 && $previousaction === 'ask_guiding_question') {
            if (!$signals[self::SIGNAL_CONFIRMS_UNDERSTANDING] && !$isaffirmative) {
                $signals[self::SIGNAL_UNCERTAINTY] = true;
            }
        }

        // "No" or "Not really" after a question = blocked/confused.
        if (preg_match('/^(no|not\s+really|nope|nah)[.!]?$/i', $message)) {
            $signals[self::SIGNAL_CONFUSION] = true;
            $signals[self::SIGNAL_UNCERTAINTY] = false;
        }

        // Single question mark or "?" often means needs clarification.
        if (trim($message) === '?') {
            $signals[self::SIGNAL_NEEDS_CLARIFICATION] = true;
        }

        // All caps might indicate frustration.
        if (mb_strlen($message) > 10 && $message === mb_strtoupper($message)) {
            $signals[self::SIGNAL_FRUSTRATION] = true;
        }

        // "everything" or "all of it" after explanation = still confused about topic.
        if (preg_match('/^(everything|all\s+of\s+it|all|the\s+whole\s+thing)[.!]?$/i', $message)) {
            if (in_array($previousaction, ['explain_concept', 'ask_guiding_question', 'rephrase_instruction'])) {
                $signals[self::SIGNAL_CONFUSION] = true;
                $signals[self::SIGNAL_NEW_QUESTION] = false; // NOT a new topic.
            }
        }

        return $signals;
    }

    /**
     * Detect if student lacks mental model of the concept.
     *
     * This detection uses:
     * 1. Explicit patterns ("I don't understand anything", "What is that?")
     * 2. Context-based heuristics (first interaction, repeated confusion after explanations)
     *
     * @param string $message The student message.
     * @param array $context Conversation context.
     * @return bool True if student appears to lack mental model.
     */
    private function detect_lacks_mental_model(string $message, array $context): bool {
        // Check explicit patterns first.
        if ($this->matches_patterns($message, self::LACKS_MENTAL_MODEL_PATTERNS)) {
            return true;
        }

        // Check context-based indicators.
        $explanationcount = $context['explanation_count'] ?? 0;
        $previousaction = $context['last_action'] ?? '';
        $isnewconversation = $context['is_new_conversation'] ?? false;
        $lowermessage = strtolower(trim($message));

        // First message in a new conversation asking a question = likely no mental model.
        if ($isnewconversation && $this->matches_patterns($message, self::NEW_QUESTION_PATTERNS)) {
            return true;
        }

        // If student says "everything" or "all of it" after an explanation = no mental model built.
        if (preg_match('/^(everything|all\s+of\s+it|all|the\s+whole\s+thing)[.!?]*$/i', $lowermessage)) {
            if (in_array($previousaction, ['explain_concept', 'rephrase_instruction', 'give_example'])) {
                return true;
            }
        }

        // If after 2+ explanations student still shows confusion signals = model not forming.
        if ($explanationcount >= 2) {
            $confusionpatterns = [
                '/still\s+(don\'?t|confused|lost)/i',
                '/makes?\s+no\s+sense/i',
                '/i\'?m\s+(still\s+)?not\s+getting/i',
            ];
            foreach ($confusionpatterns as $pattern) {
                if (preg_match($pattern, $message)) {
                    return true;
                }
            }
        }

        // Vague/circular responses after explanation attempt = unable to reformulate.
        if ($explanationcount >= 1 && $previousaction === 'explain_concept') {
            // Very short response (1-2 words) that's not affirmative = possibly stuck.
            $wordcount = str_word_count($message);
            if ($wordcount <= 2) {
                // Check if it's NOT an affirmative response.
                if (!preg_match('/^(yes|yeah|ok|okay|sure|got\s+it|i\s+see)[.!?]*$/i', $lowermessage)) {
                    // Single word responses that aren't affirmative after explanation = stuck.
                    if (preg_match('/^(what|huh|um+|uh+)[.?]*$/i', $lowermessage)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if the message is a pushback/correction from the student.
     *
     * Pushback occurs when the student is correcting what the tutor said,
     * pointing out that something was the tutor's idea (not theirs), or
     * disagreeing with how the conversation is going.
     *
     * @param string $lowermessage Lowercase message.
     * @return bool True if this is a pushback message.
     */
    private function is_pushback_message(string $lowermessage): bool {
        // Patterns that indicate student is correcting/pushing back.
        $pushbackpatterns = [
            '/you\s+(just\s+)?(came\s+up\s+with|said|mentioned|brought\s+up|made\s+up)/',
            '/that\'?s\s+(your|what\s+you)/',
            '/that\'?s\s+not\s+what\s+i\s+(asked|said|meant)/',
            '/i\s+didn\'?t\s+(say|ask|mean)/',
            '/you\'?re\s+(the\s+one|explaining)/',
            '/i\s+never\s+(said|asked|mentioned)/',
            '/that\s+was\s+your\s+(idea|example|analogy)/',
            '/why\s+are\s+you\s+(asking|saying|talking\s+about)/',
            // Combined patterns: "what do you mean" + reference to tutor's words.
            '/what\s+do\s+you\s+mean.*you\s+(just|said|came)/',
        ];

        foreach ($pushbackpatterns as $pattern) {
            if (preg_match($pattern, $lowermessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the agent's last message asked about trying/practicing.
     *
     * @param array $context Conversation context.
     * @return bool True if agent asked about trying.
     */
    private function agent_asked_about_trying(array $context): bool {
        // Get recent conversation to check last assistant message.
        $history = $context['conversation_history'] ?? [];

        if (empty($history)) {
            return false;
        }

        // Find the last assistant message.
        $lastassistantmsg = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $lastassistantmsg = strtolower($history[$i]['content'] ?? '');
                break;
            }
        }

        if ($lastassistantmsg === null) {
            return false;
        }

        // Check if it asked about trying/practicing.
        $trypatterns = [
            '/ready\s+to\s+try/',
            '/want\s+to\s+try/',
            '/like\s+to\s+try/',
            '/try\s+(an?\s+)?example/',
            '/practice/',
            '/give\s+it\s+a\s+(try|go|shot)/',
            '/attempt/',
        ];

        foreach ($trypatterns as $pattern) {
            if (preg_match($pattern, $lastassistantmsg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the primary signal (strongest/most relevant).
     *
     * Used when only one signal needs to be considered.
     * Priority order matters for state transitions.
     *
     * @param array $signals Detected signals.
     * @return string|null Primary signal or null.
     */
    public function get_primary_signal(array $signals): ?string {
        // Priority order (most important first).
        $priority = [
            self::SIGNAL_FRUSTRATION,         // Emotional state takes priority.
            self::SIGNAL_ANSWER_REQUEST,      // Academic integrity concern.
            self::SIGNAL_CLOSING,             // Student wants to end.
            self::SIGNAL_LACKS_MENTAL_MODEL,  // No foundational understanding - needs direct explanation.
            self::SIGNAL_READY_TO_PRACTICE,   // Student is ready to practice (high priority - clear intent).
            self::SIGNAL_CONFIRMS_UNDERSTANDING,
            self::SIGNAL_CONFUSION,
            self::SIGNAL_UNCERTAINTY,
            self::SIGNAL_NEEDS_CLARIFICATION,
            self::SIGNAL_WANTS_EXAMPLE,
            self::SIGNAL_ATTEMPTING,
            self::SIGNAL_NEW_QUESTION,
        ];

        foreach ($priority as $signal) {
            if ($signals[$signal] ?? false) {
                return $signal;
            }
        }

        return null;
    }

    /**
     * Check if any signal is active.
     *
     * @param array $signals Detected signals.
     * @return bool True if any signal is true.
     */
    public function has_any_signal(array $signals): bool {
        foreach ($signals as $active) {
            if ($active) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all active signals.
     *
     * @param array $signals Detected signals.
     * @return array List of active signal names.
     */
    public function get_active_signals(array $signals): array {
        return array_keys(array_filter($signals));
    }
}
