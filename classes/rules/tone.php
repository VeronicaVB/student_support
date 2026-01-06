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

namespace local_student_support\rules;

use local_student_support\agent\agent_config;

defined('MOODLE_INTERNAL') || die();

/**
 * Tone rule.
 *
 * Ensures the agent:
 * - Maintains a professional, teacher-like tone
 * - Does not adopt an informal or friend role
 * - Responds appropriately to off-topic conversations
 * - Handles inappropriate content appropriately
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tone implements rule_interface {

    /** @var agent_config Agent configuration. */
    private agent_config $config;

    /** @var array Patterns indicating requests for friendship or personal relationship. */
    private const FRIENDSHIP_PATTERNS = [
        '/\b(be\s+my|are\s+you\s+my)\s+friend/i',
        '/\b(want\s+to|wanna)\s+(hang\s+out|be\s+friends)/i',
        '/\bi\s+(love|like)\s+you/i',
        '/\b(what\'?s?\s+your|do\s+you\s+have\s+a)\s+(age|boyfriend|girlfriend)/i',
        '/\bcan\s+we\s+(chat|talk)\s+(about\s+)?(personal|life)/i',
    ];

    /** @var array Patterns indicating inappropriate content. */
    private const INAPPROPRIATE_PATTERNS = [
        // Violence.
        '/\b(kill|murder|hurt|attack)\s+(someone|people|them)/i',
        // Explicit content requests.
        '/\b(explicit|sexual|adult)\s+content/i',
        // Harmful activities.
        '/\bhow\s+to\s+(make|build)\s+(a\s+)?(bomb|weapon|drug)/i',
        // Self-harm.
        '/\b(hurt|harm|kill)\s+myself/i',
    ];

    /** @var array Patterns indicating off-topic conversation. */
    private const OFF_TOPIC_PATTERNS = [
        '/\b(what\'?s?\s+the\s+weather|tell\s+me\s+a\s+joke)/i',
        '/\b(play\s+a\s+game|let\'?s?\s+play)/i',
        '/\bwhat\'?s?\s+your\s+favorite\s+(color|food|movie)/i',
    ];

    /**
     * Constructor.
     *
     * @param agent_config $config Agent configuration.
     */
    public function __construct(agent_config $config) {
        $this->config = $config;
    }

    /**
     * Get the rule name.
     *
     * @return string Rule name identifier.
     */
    public function get_name(): string {
        return 'tone';
    }

    /**
     * Get the rule description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Ensures the agent maintains appropriate professional tone and boundaries';
    }

    /**
     * Evaluate the rule against context and intent.
     *
     * @param array $context Current context.
     * @param array $intent Detected intent.
     * @return array Result with 'blocked', 'reason', and 'suggestion'.
     */
    public function evaluate(array $context, array $intent): array {
        $usermessage = $context['user_message'] ?? '';

        // Check for inappropriate content first (highest priority).
        foreach (self::INAPPROPRIATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                // Check for self-harm specifically.
                if (preg_match('/\b(hurt|harm|kill)\s+myself/i', $usermessage)) {
                    return [
                        'blocked' => true,
                        'reason' => 'self_harm_concern',
                        'message' => 'I\'m concerned about what you\'ve shared. Please talk to a trusted adult, counselor, or teacher right away. If you\'re in crisis, please contact a crisis helpline. I\'m here to help with learning, but this is something that needs human support.',
                        'suggestion' => 'Express genuine concern and direct to appropriate resources.',
                    ];
                }

                return [
                    'blocked' => true,
                    'reason' => 'inappropriate_content',
                    'message' => 'I\'m here to help with your learning and schoolwork. I can\'t help with that type of request. Is there something related to your studies I can help you with?',
                    'suggestion' => 'Decline clearly but kindly, and redirect to learning topics.',
                ];
            }
        }

        // Check for friendship/personal relationship requests.
        foreach (self::FRIENDSHIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => false, // Not blocked, but should maintain boundaries.
                    'reason' => 'friendship_request',
                    'message' => null,
                    'suggestion' => 'Maintain professional teacher role. Acknowledge kindly but redirect to learning support.',
                    'tone_adjustment' => 'professional_redirect',
                ];
            }
        }

        // Check for off-topic conversation.
        foreach (self::OFF_TOPIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => false, // Not blocked, but should redirect.
                    'reason' => 'off_topic',
                    'message' => null,
                    'suggestion' => 'Briefly acknowledge but gently redirect to learning topics.',
                    'tone_adjustment' => 'gentle_redirect',
                ];
            }
        }

        // Check for excessive informal language (many slang terms, etc.).
        // This is a lighter check - don't block, just note for tone adjustment.
        $informalcount = $this->count_informal_markers($usermessage);
        if ($informalcount > 3) {
            return [
                'blocked' => false,
                'reason' => 'informal_language',
                'message' => null,
                'suggestion' => 'Respond professionally while being friendly. Model appropriate academic language.',
                'tone_adjustment' => 'model_formality',
            ];
        }

        // No tone issues detected.
        return [
            'blocked' => false,
            'reason' => null,
            'message' => null,
            'suggestion' => null,
        ];
    }

    /**
     * Count informal language markers in a message.
     *
     * @param string $message The message to check.
     * @return int Count of informal markers.
     */
    private function count_informal_markers(string $message): int {
        $informalmarkers = [
            '/\blol\b/i',
            '/\bomg\b/i',
            '/\bbtw\b/i',
            '/\bidk\b/i',
            '/\bu\b(?!\.)/i', // 'u' not followed by period.
            '/\br\b(?!\.)/i', // 'r' not followed by period.
            '/\bthx\b/i',
            '/\bpls\b/i',
            '/\bbruh\b/i',
            '/\bfr\b/i',
            '/\bno\s+cap\b/i',
        ];

        $count = 0;
        foreach ($informalmarkers as $pattern) {
            if (preg_match($pattern, $message)) {
                $count++;
            }
        }

        return $count;
    }
}
