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

namespace local_student_support\agent\actions;

use local_student_support\agent\agent_config;
use local_student_support\agent\agent_memory;

defined('MOODLE_INTERNAL') || die();

/**
 * Interface for agent actions.
 *
 * All agent actions must implement this interface.
 * Actions are responsible for generating responses using the AI provider.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface action_interface {

    /**
     * Get the action name.
     *
     * @return string Action name identifier.
     */
    public function get_name(): string;

    /**
     * Get the action description.
     *
     * @return string Human-readable description.
     */
    public function get_description(): string;

    /**
     * Check if this is a guidance action (counts toward attempt limit).
     *
     * @return bool True if this is a guidance action.
     */
    public function is_guidance_action(): bool;

    /**
     * Execute the action.
     *
     * @param array $context Gathered context from GAME loop.
     * @param array $analysis Analysis results from GAME loop.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return array Response with 'success', 'message', and 'metadata'.
     */
    public function execute(
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): array;

    /**
     * Build the prompt for this action.
     *
     * @param array $context Gathered context.
     * @param array $analysis Analysis results.
     * @param agent_config $config Agent configuration.
     * @param agent_memory $memory Agent memory.
     * @return string The prompt to send to the AI.
     */
    public function build_prompt(
        array $context,
        array $analysis,
        agent_config $config,
        agent_memory $memory
    ): string;
}
