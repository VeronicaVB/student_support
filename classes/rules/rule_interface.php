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
 * Interface for agent rules.
 *
 * Rules evaluate context and intent to determine if
 * certain actions should be blocked or modified.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface rule_interface {

    /**
     * Get the rule name.
     *
     * @return string Rule name identifier.
     */
    public function get_name(): string;

    /**
     * Get the rule description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string;

    /**
     * Evaluate the rule against context and intent.
     *
     * @param array $context Current context.
     * @param array $intent Detected intent.
     * @return array Result with 'blocked', 'reason', and 'suggestion'.
     */
    public function evaluate(array $context, array $intent): array;
}
