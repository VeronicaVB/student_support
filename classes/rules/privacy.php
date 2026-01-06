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

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy rule.
 *
 * Ensures the agent does not:
 * - Request personal or sensitive information
 * - Engage with content containing personal data
 * - Store or process sensitive information inappropriately
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy implements rule_interface {

    /** @var array Patterns indicating personal information in message. */
    private const PERSONAL_INFO_PATTERNS = [
        // Phone numbers.
        '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/',
        // Email patterns (detect if student is sharing).
        '/\bmy\s+(email|e-mail)\s+(is|:)\s*\S+@\S+/i',
        // Address patterns.
        '/\b(my\s+)?(home\s+)?address\s+(is|:)/i',
        // Social security / ID numbers.
        '/\b(ssn|social\s+security|id\s+number)\s*(is|:)/i',
        // Passwords.
        '/\b(my\s+)?password\s+(is|:)/i',
        // Credit card patterns.
        '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
    ];

    /** @var array Patterns where agent might be asked for personal info. */
    private const REQUESTING_INFO_PATTERNS = [
        '/\b(what\'?s?|tell\s+me)\s+(your|the)\s+(name|email|phone|address)/i',
        '/\b(give\s+me|share)\s+(your|personal)\s+(information|details)/i',
    ];

    /** @var array Patterns indicating discussion of other students. */
    private const OTHER_STUDENT_PATTERNS = [
        '/\b(tell\s+me\s+about|what\s+did)\s+\w+\'?s?\s+(grades?|answers?|work)/i',
        '/\bhow\s+did\s+\w+\s+(do|score|perform)/i',
        '/\bcompare\s+(me|my\s+work)\s+(to|with)\s+\w+/i',
    ];

    /**
     * Get the rule name.
     *
     * @return string Rule name identifier.
     */
    public function get_name(): string {
        return 'privacy';
    }

    /**
     * Get the rule description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Ensures the agent protects student privacy and does not process sensitive information';
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

        // Check if message contains personal information.
        foreach (self::PERSONAL_INFO_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => true,
                    'reason' => 'personal_info_detected',
                    'message' => 'I noticed you may have shared some personal information. For your privacy and safety, please don\'t share personal details like phone numbers, addresses, or passwords. I can still help you with your learning questions!',
                    'suggestion' => 'Gently remind about privacy and redirect to the learning topic.',
                ];
            }
        }

        // Check if asking about the agent's personal info (should clarify it's an AI).
        foreach (self::REQUESTING_INFO_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => false, // Not blocked, but should be handled specially.
                    'reason' => 'asking_agent_info',
                    'message' => null,
                    'suggestion' => 'Clarify that you are an AI assistant and redirect to learning support.',
                ];
            }
        }

        // Check if asking about other students.
        foreach (self::OTHER_STUDENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => true,
                    'reason' => 'other_student_info',
                    'message' => 'I can only help you with your own learning. I can\'t share information about other students. Is there something about your own work I can help you understand?',
                    'suggestion' => 'Redirect to helping with their own work.',
                ];
            }
        }

        // No privacy violations detected.
        return [
            'blocked' => false,
            'reason' => null,
            'message' => null,
            'suggestion' => null,
        ];
    }
}
