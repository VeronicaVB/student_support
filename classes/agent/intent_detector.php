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
 * Intent detector - LEGACY WRAPPER.
 *
 * This class now delegates to signal_detector for pattern matching.
 * It translates signals into legacy intent format for backward compatibility.
 *
 * NEW CODE SHOULD USE:
 * - signal_detector: for detecting boolean signals
 * - cognitive_state: for tracking student state
 * - state_transition_engine: for computing state changes
 * - action_policy: for selecting actions
 *
 * This class is maintained for backward compatibility only.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated Use signal_detector and cognitive_state instead.
 */
class intent_detector {

    /** @var string Intent: Student is asking a question. */
    public const INTENT_ASK_QUESTION = 'ask_question';

    /** @var string Intent: Student needs help understanding. */
    public const INTENT_NEED_HELP = 'need_help';

    /** @var string Intent: Student is requesting a direct answer. */
    public const INTENT_REQUEST_ANSWER = 'request_answer';

    /** @var string Intent: Student is confirming understanding. */
    public const INTENT_CONFIRM_UNDERSTANDING = 'confirm_understanding';

    /** @var string Intent: Student is expressing frustration. */
    public const INTENT_EXPRESS_FRUSTRATION = 'express_frustration';

    /** @var string Intent: Student is asking for clarification. */
    public const INTENT_ASK_CLARIFICATION = 'ask_clarification';

    /** @var string Intent: Student wants an example. */
    public const INTENT_WANT_EXAMPLE = 'want_example';

    /** @var string Intent: Student is ending the conversation. */
    public const INTENT_END_CONVERSATION = 'end_conversation';

    /** @var string Intent: General conversation / unknown. */
    public const INTENT_GENERAL = 'general';

    /** @var signal_detector Signal detector instance. */
    private signal_detector $signaldetector;

    /** @var array Mapping from signals to legacy intents. */
    private const SIGNAL_TO_INTENT = [
        signal_detector::SIGNAL_ANSWER_REQUEST => self::INTENT_REQUEST_ANSWER,
        signal_detector::SIGNAL_FRUSTRATION => self::INTENT_EXPRESS_FRUSTRATION,
        signal_detector::SIGNAL_CLOSING => self::INTENT_END_CONVERSATION,
        signal_detector::SIGNAL_CONFIRMS_UNDERSTANDING => self::INTENT_CONFIRM_UNDERSTANDING,
        signal_detector::SIGNAL_WANTS_EXAMPLE => self::INTENT_WANT_EXAMPLE,
        signal_detector::SIGNAL_NEEDS_CLARIFICATION => self::INTENT_ASK_CLARIFICATION,
        signal_detector::SIGNAL_CONFUSION => self::INTENT_NEED_HELP,
        signal_detector::SIGNAL_NEW_QUESTION => self::INTENT_ASK_QUESTION,
        signal_detector::SIGNAL_UNCERTAINTY => self::INTENT_NEED_HELP,
        signal_detector::SIGNAL_ATTEMPTING => self::INTENT_CONFIRM_UNDERSTANDING,
    ];

    /** @var array Keywords that suggest academic subject topics. */
    private const TOPIC_KEYWORDS = [
        'math' => [
            'math', 'mathematics', 'algebra', 'geometry', 'calculus', 'equation', 'formula',
            'number', 'fraction', 'multiplication', 'division', 'addition', 'subtraction',
            'percentage', 'decimal', 'integer', 'ratio', 'proportion', 'exponent', 'square root',
        ],
        'science' => [
            'science', 'physics', 'chemistry', 'biology', 'experiment', 'hypothesis', 'molecule',
            'atom', 'cell', 'energy', 'force', 'gravity', 'photosynthesis', 'evolution',
        ],
        'english' => [
            'english', 'essay', 'grammar', 'writing', 'reading', 'literature', 'poem', 'story',
            'verb', 'noun', 'adjective', 'sentence', 'paragraph', 'spelling', 'vocabulary',
        ],
        'history' => [
            'history', 'historical', 'war', 'civilization', 'century', 'revolution', 'ancient',
            'empire', 'colonization', 'independence', 'treaty', 'democracy',
        ],
        'programming' => [
            'code', 'programming', 'function', 'variable', 'loop', 'algorithm', 'debug',
            'array', 'object', 'class', 'method', 'syntax', 'compiler', 'database',
        ],
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        $this->signaldetector = new signal_detector();
    }

    /**
     * Detect the intent of a user message.
     *
     * This method now delegates to signal_detector and translates
     * signals to legacy intent format.
     *
     * @param string $message The user's message.
     * @param array $context Additional context.
     * @return array Intent information with 'type', 'confidence', 'topic', and 'signals'.
     */
    public function detect(string $message, array $context = []): array {
        $message = trim($message);
        $lowermessage = strtolower($message);

        // Detect signals using the new signal_detector.
        $signals = $this->signaldetector->detect($message, $context);

        // Get primary signal and translate to legacy intent.
        $primarysignal = $this->signaldetector->get_primary_signal($signals);
        $detectedintent = $this->translate_signal_to_intent($primarysignal, $message);

        // Calculate confidence based on active signals.
        $activesignals = $this->signaldetector->get_active_signals($signals);
        $confidence = $this->calculate_confidence($activesignals, $primarysignal);

        // Detect topic.
        $topic = $this->detect_topic($lowermessage, $context);

        return [
            'type' => $detectedintent,
            'confidence' => $confidence,
            'topic' => $topic,
            'message_length' => strlen($message),
            'is_question' => $this->is_question($message),
            // New: include signals for new architecture.
            'signals' => $signals,
            'primary_signal' => $primarysignal,
            'active_signals' => $activesignals,
        ];
    }

    /**
     * Translate a signal to legacy intent format.
     *
     * @param string|null $primarysignal Primary signal.
     * @param string $message Original message.
     * @return string Legacy intent constant.
     */
    private function translate_signal_to_intent(?string $primarysignal, string $message): string {
        // If we have a primary signal, use the mapping.
        if ($primarysignal !== null && isset(self::SIGNAL_TO_INTENT[$primarysignal])) {
            return self::SIGNAL_TO_INTENT[$primarysignal];
        }

        // If no specific signal but message is a question.
        if ($this->is_question($message)) {
            return self::INTENT_ASK_QUESTION;
        }

        return self::INTENT_GENERAL;
    }

    /**
     * Calculate confidence based on active signals.
     *
     * @param array $activesignals List of active signal names.
     * @param string|null $primarysignal Primary signal.
     * @return float Confidence score.
     */
    private function calculate_confidence(array $activesignals, ?string $primarysignal): float {
        if (empty($activesignals)) {
            return 0.3; // Low confidence for no signals.
        }

        $signalcount = count($activesignals);

        // Base confidence for having a primary signal.
        $baseconfidence = ($primarysignal !== null) ? 0.6 : 0.4;

        // Additional confidence for multiple confirming signals.
        $additionalconfidence = min(0.35, $signalcount * 0.1);

        return min(0.95, $baseconfidence + $additionalconfidence);
    }

    /**
     * Check if a message is a question.
     *
     * @param string $message The message to check.
     * @return bool True if the message appears to be a question.
     */
    private function is_question(string $message): bool {
        // Check for question mark.
        if (strpos($message, '?') !== false) {
            return true;
        }

        // Check for question starters.
        $questionstarters = [
            '/^(what|where|when|why|who|how|which|whose|whom)\b/i',
            '/^(is|are|was|were|do|does|did|can|could|would|should|will|have|has)\b/i',
        ];

        foreach ($questionstarters as $pattern) {
            if (preg_match($pattern, trim($message))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the topic of the message.
     *
     * PRIORITY ORDER:
     * 1. If message contains a REAL subject topic keyword → use that topic
     * 2. If message contains meta-words (explanation, answer, etc.) → keep current topic
     * 3. If no topic detected → keep current topic
     *
     * @param string $lowermessage Lowercase message.
     * @param array $context Additional context.
     * @return string|null Detected topic or null.
     */
    private function detect_topic(string $lowermessage, array $context = []): ?string {
        // Get current topic from context/memory.
        $currenttopic = $context['memory_summary']['current_topic'] ?? null;

        // PRIORITY 1: Check for REAL subject topic keywords in message.
        // These are actual academic subjects, not meta-words about learning.
        foreach (self::TOPIC_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowermessage, $keyword) !== false) {
                    // Found a real topic keyword.
                    // If it's a different category than current topic, switch to it.
                    if ($currenttopic === null || $category !== $this->get_topic_category($currenttopic)) {
                        return $category;
                    }
                    // Same category - keep the more specific current topic if it exists.
                    return $currenttopic;
                }
            }
        }

        // PRIORITY 2: Try to extract specific topic from message.
        // But only accept it if it's NOT a meta-word.
        $specifictopic = $this->extract_specific_topic($lowermessage);
        if ($specifictopic !== null) {
            // extract_specific_topic already filters meta-words, so this is a real topic.
            return $specifictopic;
        }

        // PRIORITY 3: No new topic found - keep current topic.
        // This handles messages like "I don't understand the explanation" where
        // "explanation" is a meta-word and should NOT override the current topic.
        return $currenttopic;
    }

    /**
     * Extract a specific topic from the message (e.g., "multiplication", "photosynthesis").
     *
     * @param string $message The message to analyze.
     * @return string|null Specific topic or null.
     */
    private function extract_specific_topic(string $message): ?string {
        // Patterns to extract specific topics.
        // NOTE: These patterns are carefully crafted to avoid capturing trailing articles/words.
        $patterns = [
            // "about X", "regarding X", "on X" - capture single word only.
            '/\b(?:about|regarding|on|with)\s+([a-z]+)\b/i',
            // "understand X", "learn X", "help with X" - capture single word only.
            '/\b(?:understand|learn|help\s+with|confused\s+about|struggling\s+with)\s+(?:the\s+)?([a-z]+)\b/i',
            // "X is confusing", "X doesn't make sense" - capture single word only.
            '/^([a-z]+)\s+(?:is|are|doesn\'t|does\s+not|isn\'t)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                $extracted = trim($matches[1]);
                // Filter out common non-topics AND meta-words (words about learning, not subjects).
                // Also filter if the extracted word is a non-topic.
                if (!$this->is_non_topic_word($extracted) && strlen($extracted) > 2) {
                    return $extracted;
                }
            }
        }

        return null;
    }

    /**
     * Check if a word is a non-topic (pronoun, meta-word about learning, etc.).
     *
     * Meta-words are words that refer to the PROCESS of learning (explanation, answer,
     * question) rather than SUBJECT MATTER (fractions, photosynthesis, algebra).
     * These should NOT override an existing topic.
     *
     * @param string $word The word to check.
     * @return bool True if this is a non-topic word.
     */
    private function is_non_topic_word(string $word): bool {
        $lowerword = strtolower($word);

        // Pronouns and generic references.
        $pronouns = ['it', 'this', 'that', 'everything', 'anything', 'something', 'nothing', 'me', 'you', 'i'];

        // Meta-words about learning process (NOT subject matter).
        $metawords = [
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
            'test', 'exam', 'quiz',
            'help', 'hint', 'hints',
            'idea', 'ideas',
            'thing', 'things', 'stuff',
            'part', 'parts',
            'step', 'steps',
            'way', 'method',
        ];

        return in_array($lowerword, $pronouns) || in_array($lowerword, $metawords);
    }

    /**
     * Get the general category for a topic.
     *
     * @param string|null $topic The specific topic.
     * @return string|null The category or null.
     */
    private function get_topic_category(?string $topic): ?string {
        if ($topic === null) {
            return null;
        }

        $lowertopic = strtolower($topic);

        foreach (self::TOPIC_KEYWORDS as $category => $keywords) {
            // Check if topic IS a category.
            if ($lowertopic === $category) {
                return $category;
            }
            // Check if topic contains a keyword.
            foreach ($keywords as $keyword) {
                if (strpos($lowertopic, $keyword) !== false) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Get all valid intent types.
     *
     * @return array List of intent type constants.
     */
    public static function get_all_intents(): array {
        return [
            self::INTENT_ASK_QUESTION,
            self::INTENT_NEED_HELP,
            self::INTENT_REQUEST_ANSWER,
            self::INTENT_CONFIRM_UNDERSTANDING,
            self::INTENT_EXPRESS_FRUSTRATION,
            self::INTENT_ASK_CLARIFICATION,
            self::INTENT_WANT_EXAMPLE,
            self::INTENT_END_CONVERSATION,
            self::INTENT_GENERAL,
        ];
    }

    /**
     * Check if an intent indicates the student wants direct answers.
     *
     * @param string $intenttype The intent type.
     * @return bool True if the intent is for direct answers.
     */
    public static function is_answer_seeking_intent(string $intenttype): bool {
        return $intenttype === self::INTENT_REQUEST_ANSWER;
    }

    /**
     * Check if an intent indicates the student needs support.
     *
     * @param string $intenttype The intent type.
     * @return bool True if the intent indicates need for support.
     */
    public static function is_support_needed_intent(string $intenttype): bool {
        return in_array($intenttype, [
            self::INTENT_NEED_HELP,
            self::INTENT_ASK_QUESTION,
            self::INTENT_ASK_CLARIFICATION,
            self::INTENT_EXPRESS_FRUSTRATION,
            self::INTENT_WANT_EXAMPLE,
        ], true);
    }

    /**
     * Get the underlying signal detector.
     *
     * Use this for direct access to signals in new code.
     *
     * @return signal_detector The signal detector instance.
     */
    public function get_signal_detector(): signal_detector {
        return $this->signaldetector;
    }
}
