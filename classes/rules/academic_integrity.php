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

use local_student_support\agent\intent_detector;

defined('MOODLE_INTERNAL') || die();

/**
 * Academic integrity rule.
 *
 * Ensures the agent does not:
 * - Provide direct answers to assessable tasks
 * - Complete homework or assignments
 * - Write essays, code, or solutions
 * - Facilitate plagiarism or cheating
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class academic_integrity implements rule_interface {

    /** @var array Patterns indicating requests for complete solutions. */
    private const SOLUTION_REQUEST_PATTERNS = [
        '/\bwrite\s+(my|the|this|an?)\s+(essay|assignment|homework|report|paper)/i',
        '/\bdo\s+(my|the|this)\s+(homework|assignment|work|task)/i',
        '/\bcomplete\s+(this|the|my)\s+(for\s+me|assignment|task)/i',
        '/\bsolve\s+(this|the|it)\s+(for\s+me|completely)/i',
        '/\bgive\s+me\s+(the|a)\s+(full|complete|entire)\s+(solution|answer|code)/i',
        '/\bfinish\s+(this|my|the)\s+(assignment|homework|work)/i',
        '/\bcode\s+(this|it)\s+for\s+me/i',
        '/\bwrite\s+(the|my)\s+code/i',
    ];

    /** @var array Patterns indicating cheating or plagiarism intent. */
    private const CHEATING_PATTERNS = [
        '/\b(copy|cheat|plagiar)/i',
        '/\bdon\'?t\s+tell\s+(my\s+)?teacher/i',
        '/\bwithout\s+(getting\s+)?caught/i',
        '/\bsubmit\s+as\s+my\s+own/i',
        '/\bpass\s+(off|it)\s+as\s+(my\s+)?own/i',
    ];

    /** @var array Patterns indicating exam or test assistance. */
    private const EXAM_PATTERNS = [
        '/\b(during|in)\s+(my|the|an?)\s+(exam|test|quiz)/i',
        '/\bhelp\s+(me\s+)?(on|with|during)\s+(the|my|an?)\s+(exam|test)/i',
        '/\banswers?\s+(for|to)\s+(the|my|this)\s+(exam|test|quiz)/i',
    ];

    /**
     * Get the rule name.
     *
     * @return string Rule name identifier.
     */
    public function get_name(): string {
        return 'academic_integrity';
    }

    /**
     * Get the rule description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string {
        return 'Ensures the agent maintains academic integrity and does not facilitate cheating';
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

        // Check if intent is requesting direct answers.
        if ($intent['type'] === intent_detector::INTENT_REQUEST_ANSWER) {
            return [
                'blocked' => true,
                'reason' => 'request_direct_answer',
                'message' => get_string('message:refusedirectanswer', 'local_student_support'),
                'suggestion' => 'Redirect using guiding questions to help the student discover the answer.',
            ];
        }

        // Check for solution request patterns.
        foreach (self::SOLUTION_REQUEST_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => true,
                    'reason' => 'request_complete_solution',
                    'message' => get_string('message:refusedirectanswer', 'local_student_support'),
                    'suggestion' => 'Offer to help understand the concepts instead of completing the work.',
                ];
            }
        }

        // Check for cheating patterns.
        foreach (self::CHEATING_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => true,
                    'reason' => 'cheating_intent',
                    'message' => 'I\'m here to help you learn, not to help with anything that would go against academic honesty policies. Let me know if you\'d like help understanding the material instead.',
                    'suggestion' => 'Firmly but kindly redirect to legitimate learning support.',
                ];
            }
        }

        // Check for exam assistance patterns.
        foreach (self::EXAM_PATTERNS as $pattern) {
            if (preg_match($pattern, $usermessage)) {
                return [
                    'blocked' => true,
                    'reason' => 'exam_assistance',
                    'message' => 'I can\'t help during exams or tests. However, I\'d be happy to help you prepare before your exam or review concepts afterward.',
                    'suggestion' => 'Offer study help for before or after the exam.',
                ];
            }
        }

        // No violations detected.
        return [
            'blocked' => false,
            'reason' => null,
            'message' => null,
            'suggestion' => null,
        ];
    }
}
