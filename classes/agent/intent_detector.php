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
 * Intent detector for the Student Support Agent.
 *
 * Analyzes user messages to determine their intent.
 * This is a rule-based intent detector that uses pattern matching
 * and keyword analysis. It does NOT use AI for intent detection
 * to maintain control over the agent's behavior.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

    /** @var array Patterns for detecting "request answer" intent. */
    private const REQUEST_ANSWER_PATTERNS = [
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

    /** @var array Patterns for detecting "need help" intent. */
    private const NEED_HELP_PATTERNS = [
        '/\b(i\s+)?(don\'?t|do\s+not)\s+understand/i',
        '/\b(i\'?m|i\s+am)\s+(confused|stuck|lost)/i',
        '/\bcan\s+you\s+(help|explain|clarify)/i',
        '/\bhelp\s+me\s+(understand|with|figure)/i',
        '/\bi\s+need\s+help/i',
        '/\bwhat\s+does\s+(this|that|it)\s+mean/i',
        '/\bhow\s+do\s+(i|you|we)/i',
        '/\bcan\s+you\s+show\s+me\s+how/i',
        '/\bi\'?m\s+having\s+trouble/i',
    ];

    /** @var array Patterns for detecting "want example" intent. */
    private const WANT_EXAMPLE_PATTERNS = [
        '/\b(give|show)\s+(me\s+)?(an?\s+)?example/i',
        '/\bfor\s+example/i',
        '/\bcan\s+you\s+(give|show)\s+(me\s+)?(an?\s+)?example/i',
        '/\blike\s+what/i',
        '/\bsuch\s+as/i',
        '/\bwhat\s+would\s+(this|that|it)\s+look\s+like/i',
    ];

    /** @var array Patterns for detecting "frustration" intent. */
    private const FRUSTRATION_PATTERNS = [
        '/\bthis\s+(is\s+)?(so\s+)?(frustrating|annoying|stupid|dumb)/i',
        '/\bi\s+(give\s+up|quit|can\'?t\s+do\s+(this|it))/i',
        '/\b(this|it)\s+makes\s+no\s+sense/i',
        '/\bi\'?m\s+(so\s+)?(frustrated|annoyed|angry)/i',
        '/\bwhy\s+(is\s+)?(this|it)\s+so\s+(hard|difficult|confusing)/i',
        '/\bi\s+hate\s+(this|it)/i',
        '/\bthis\s+is\s+impossible/i',
    ];

    /** @var array Patterns for detecting "clarification" intent. */
    private const CLARIFICATION_PATTERNS = [
        '/\bwhat\s+do\s+you\s+mean/i',
        '/\bcan\s+you\s+(clarify|explain\s+that|be\s+more\s+specific)/i',
        '/\bi\'?m\s+not\s+sure\s+(i\s+)?understand/i',
        '/\bcould\s+you\s+(rephrase|say\s+that\s+again|explain\s+differently)/i',
        '/\bin\s+other\s+words/i',
        '/\bwhat\s+does\s+that\s+mean/i',
    ];

    /** @var array Patterns for detecting "confirm understanding" intent. */
    private const CONFIRM_UNDERSTANDING_PATTERNS = [
        '/\b(i\s+)(think\s+i\s+)?(understand|get\s+it|see)\s+(now)?/i',
        '/\b(oh|ah),?\s+(i\s+)?(see|get\s+it|understand)/i',
        '/\bthat\s+makes\s+sense/i',
        '/\bso\s+(you\'?re\s+saying|it\'?s\s+like|basically)/i',
        '/\bgot\s+it/i',
        '/\bi\s+see\s+what\s+you\s+mean/i',
    ];

    /** @var array Patterns for detecting "end conversation" intent. */
    private const END_CONVERSATION_PATTERNS = [
        '/\b(thanks?|thank\s+you)(\s+for\s+(your\s+)?help)?[.!]?$/i',
        '/\b(bye|goodbye|see\s+you|later)/i',
        '/\bthat\'?s\s+all\s+(i\s+needed|for\s+now)/i',
        '/\bi\'?m\s+(good|done|fine)\s+(now|for\s+now)?/i',
        '/\bno\s+more\s+questions/i',
    ];

    /** @var array Keywords that suggest academic subject topics. */
    private const TOPIC_KEYWORDS = [
        'math' => ['math', 'algebra', 'geometry', 'calculus', 'equation', 'formula', 'number', 'fraction'],
        'science' => ['science', 'physics', 'chemistry', 'biology', 'experiment', 'hypothesis', 'molecule'],
        'english' => ['english', 'essay', 'grammar', 'writing', 'reading', 'literature', 'poem', 'story'],
        'history' => ['history', 'historical', 'war', 'civilization', 'century', 'revolution', 'ancient'],
        'programming' => ['code', 'programming', 'function', 'variable', 'loop', 'algorithm', 'debug'],
    ];

    /**
     * Detect the intent of a user message.
     *
     * @param string $message The user's message.
     * @param array $context Additional context.
     * @return array Intent information with 'type', 'confidence', and 'topic'.
     */
    public function detect(string $message, array $context = []): array {
        $message = trim($message);
        $lowermessage = strtolower($message);

        // Check for each intent type in priority order.
        $intents = [
            self::INTENT_REQUEST_ANSWER => $this->check_patterns($message, self::REQUEST_ANSWER_PATTERNS),
            self::INTENT_EXPRESS_FRUSTRATION => $this->check_patterns($message, self::FRUSTRATION_PATTERNS),
            self::INTENT_END_CONVERSATION => $this->check_patterns($message, self::END_CONVERSATION_PATTERNS),
            self::INTENT_CONFIRM_UNDERSTANDING => $this->check_patterns($message, self::CONFIRM_UNDERSTANDING_PATTERNS),
            self::INTENT_WANT_EXAMPLE => $this->check_patterns($message, self::WANT_EXAMPLE_PATTERNS),
            self::INTENT_ASK_CLARIFICATION => $this->check_patterns($message, self::CLARIFICATION_PATTERNS),
            self::INTENT_NEED_HELP => $this->check_patterns($message, self::NEED_HELP_PATTERNS),
        ];

        // Find the highest confidence intent.
        $detectedintent = self::INTENT_GENERAL;
        $confidence = 0.3; // Default low confidence for general.

        foreach ($intents as $intenttype => $intentconfidence) {
            if ($intentconfidence > $confidence) {
                $detectedintent = $intenttype;
                $confidence = $intentconfidence;
            }
        }

        // If message is a question but no specific intent detected.
        if ($detectedintent === self::INTENT_GENERAL && $this->is_question($message)) {
            $detectedintent = self::INTENT_ASK_QUESTION;
            $confidence = 0.6;
        }

        // Detect topic.
        $topic = $this->detect_topic($lowermessage, $context);

        return [
            'type' => $detectedintent,
            'confidence' => $confidence,
            'topic' => $topic,
            'message_length' => strlen($message),
            'is_question' => $this->is_question($message),
        ];
    }

    /**
     * Check a message against a set of patterns.
     *
     * @param string $message The message to check.
     * @param array $patterns Array of regex patterns.
     * @return float Confidence score (0.0 to 1.0).
     */
    private function check_patterns(string $message, array $patterns): float {
        $matches = 0;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                $matches++;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        // More matches = higher confidence, but cap at 0.95.
        return min(0.95, 0.5 + ($matches * 0.15));
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
     * @param string $lowermessage Lowercase message.
     * @param array $context Additional context.
     * @return string|null Detected topic or null.
     */
    private function detect_topic(string $lowermessage, array $context = []): ?string {
        // First, check if there's a current topic in context.
        $currenttopic = $context['memory_summary']['current_topic'] ?? null;

        // Check for topic keywords in message.
        foreach (self::TOPIC_KEYWORDS as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowermessage, $keyword) !== false) {
                    return $topic;
                }
            }
        }

        // If no new topic detected, keep current topic.
        return $currenttopic;
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
}
