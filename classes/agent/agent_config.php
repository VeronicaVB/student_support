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
 * Agent configuration class.
 *
 * Manages agent goals, student level, curriculum alignment, and behavioral rules.
 * This class is responsible for:
 * - Defining agent goals and constraints
 * - Managing curriculum configuration (site + course level)
 * - Determining student grade level (profile + course)
 * - Configuring AI provider settings
 * - Setting pedagogical parameters
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_config {

    /** @var string Pedagogical approach: Socratic questioning. */
    public const APPROACH_SOCRATIC = 'socratic';

    /** @var string Pedagogical approach: Scaffolded learning. */
    public const APPROACH_SCAFFOLDED = 'scaffolded';

    /** @var string Pedagogical approach: Exploratory guidance. */
    public const APPROACH_EXPLORATORY = 'exploratory';

    /** @var int Default maximum guidance attempts before escalation. */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /** @var float Default temperature for AI responses. */
    public const DEFAULT_TEMPERATURE = 0.7;

    /** @var int Default max tokens for AI responses. */
    public const DEFAULT_MAX_TOKENS = 1024;

    /** @var int|null The course ID for context. */
    private ?int $courseid;

    /** @var int|null The user ID for context. */
    private ?int $userid;

    /** @var array Cached site configuration. */
    private array $siteconfig;

    /** @var array|null Cached course configuration. */
    private ?array $courseconfig;

    /** @var \cache Cache instance for agent config. */
    private \cache $cache;

    /**
     * Constructor.
     *
     * @param int|null $courseid Course ID for context.
     * @param int|null $userid User ID for context.
     */
    public function __construct(?int $courseid = null, ?int $userid = null) {
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->cache = \cache::make('local_student_support', 'agentconfig');
        $this->load_configuration();
    }

    /**
     * Load configuration from database/cache.
     *
     * @return void
     */
    private function load_configuration(): void {
        $this->siteconfig = $this->load_site_config();

        if ($this->courseid !== null) {
            $this->courseconfig = $this->load_course_config($this->courseid);
        } else {
            $this->courseconfig = null;
        }
    }

    /**
     * Load site-level configuration.
     *
     * @return array Site configuration.
     */
    private function load_site_config(): array {
        $cachekey = 'site_config';
        $cached = $this->cache->get($cachekey);

        if ($cached !== false) {
            return $cached;
        }

        $config = [
            // General.
            'enabled' => (bool) get_config('local_student_support', 'enabled'),

            // AI Provider.
            'apiendpoint' => get_config('local_student_support', 'apiendpoint'),
            'apikey' => get_config('local_student_support', 'apikey'),
            'model' => get_config('local_student_support', 'model') ?: 'gpt-4',

            // Curriculum.
            'curriculumname' => get_config('local_student_support', 'curriculumname'),
            'curriculumyear' => get_config('local_student_support', 'curriculumyear'),
            'defaultgradelevel' => get_config('local_student_support', 'defaultgradelevel'),
            'subjectareas' => get_config('local_student_support', 'subjectareas'),

            // Behaviour.
            'maxattempts' => (int) get_config('local_student_support', 'maxattempts') ?: self::DEFAULT_MAX_ATTEMPTS,
            'responselanguage' => get_config('local_student_support', 'responselanguage') ?: 'en',
            'pedagogicalapproach' => get_config('local_student_support', 'pedagogicalapproach') ?: self::APPROACH_SOCRATIC,

            // AI Parameters.
            'temperature' => (float) get_config('local_student_support', 'temperature') ?: self::DEFAULT_TEMPERATURE,
            'maxtokens' => (int) get_config('local_student_support', 'maxtokens') ?: self::DEFAULT_MAX_TOKENS,

            // Privacy.
            'logconversations' => (bool) get_config('local_student_support', 'logconversations'),
            'retentionperiod' => (int) get_config('local_student_support', 'retentionperiod'),
            'anonymizedata' => (bool) get_config('local_student_support', 'anonymizedata'),
        ];

        $this->cache->set($cachekey, $config);

        return $config;
    }

    /**
     * Load course-level configuration.
     *
     * @param int $courseid Course ID.
     * @return array|null Course configuration or null if not set.
     */
    private function load_course_config(int $courseid): ?array {
        global $DB;

        $coursecache = \cache::make('local_student_support', 'courseconfig');
        $cachekey = "course_{$courseid}";
        $cached = $coursecache->get($cachekey);

        if ($cached !== false) {
            return $cached ?: null;
        }

        $record = $DB->get_record('local_studentsupport_course', ['courseid' => $courseid]);

        if (!$record) {
            $coursecache->set($cachekey, []);
            return null;
        }

        $config = [
            'enabled' => (bool) $record->enabled,
            'overridecurriculum' => (bool) $record->overridecurriculum,
            'curriculumname' => $record->curriculumname,
            'gradelevel' => $record->gradelevel,
            'subjectarea' => $record->subjectarea,
            'customconfig' => $record->customconfig ? json_decode($record->customconfig, true) : [],
        ];

        $coursecache->set($cachekey, $config);

        return $config;
    }

    /**
     * Check if the plugin is enabled globally.
     *
     * @return bool True if enabled.
     */
    public function is_enabled(): bool {
        return $this->siteconfig['enabled'] ?? false;
    }

    /**
     * Check if the plugin is enabled for the current course.
     *
     * @return bool True if enabled for course.
     */
    public function is_enabled_for_course(): bool {
        if (!$this->is_enabled()) {
            return false;
        }

        if ($this->courseconfig === null) {
            return true;
        }

        return $this->courseconfig['enabled'] ?? true;
    }

    /**
     * Check if the plugin is properly configured.
     *
     * @return bool True if all required settings are configured.
     */
    public function is_configured(): bool {
        return !empty($this->siteconfig['apiendpoint']) && !empty($this->siteconfig['apikey']);
    }

    /**
     * Get the API endpoint.
     *
     * @return string API endpoint URL.
     */
    public function get_api_endpoint(): string {
        return $this->siteconfig['apiendpoint'] ?? '';
    }

    /**
     * Get the API key.
     *
     * @return string API key.
     */
    public function get_api_key(): string {
        return $this->siteconfig['apikey'] ?? '';
    }

    /**
     * Get the AI model name.
     *
     * @return string Model name.
     */
    public function get_model(): string {
        return $this->siteconfig['model'] ?? 'gpt-4';
    }

    /**
     * Get the curriculum name.
     *
     * @return string Curriculum name.
     */
    public function get_curriculum_name(): string {
        if ($this->courseconfig !== null && $this->courseconfig['overridecurriculum']) {
            return $this->courseconfig['curriculumname'] ?? $this->siteconfig['curriculumname'] ?? '';
        }

        return $this->siteconfig['curriculumname'] ?? '';
    }

    /**
     * Get the curriculum year/version.
     *
     * @return string Curriculum year.
     */
    public function get_curriculum_year(): string {
        return $this->siteconfig['curriculumyear'] ?? '';
    }

    /**
     * Get the grade level for the current context.
     *
     * Priority: User profile > Course config > Site default.
     *
     * @return string Grade level.
     */
    public function get_grade_level(): string {
        // First, try to get from user profile if userid is set.
        if ($this->userid !== null) {
            $userlevel = $this->get_user_grade_level($this->userid);
            if (!empty($userlevel)) {
                return $userlevel;
            }
        }

        // Second, try course configuration.
        if ($this->courseconfig !== null && !empty($this->courseconfig['gradelevel'])) {
            return $this->courseconfig['gradelevel'];
        }

        // Finally, fall back to site default.
        return $this->siteconfig['defaultgradelevel'] ?? '';
    }

    /**
     * Get grade level from user profile field.
     *
     * @param int $userid User ID.
     * @return string Grade level or empty string.
     */
    private function get_user_grade_level(int $userid): string {
        global $DB;

        $fieldnames = ['gradelevel', 'year_level', 'grade_level', 'yearlevel'];

        foreach ($fieldnames as $fieldname) {
            $sql = "SELECT uid.data
                      FROM {user_info_data} uid
                      JOIN {user_info_field} uif ON uid.fieldid = uif.id
                     WHERE uid.userid = :userid
                       AND uif.shortname = :fieldname";

            $data = $DB->get_field_sql($sql, ['userid' => $userid, 'fieldname' => $fieldname]);

            if ($data !== false && !empty($data)) {
                return $data;
            }
        }

        return '';
    }

    /**
     * Get the subject area for the current context.
     *
     * @return string Subject area.
     */
    public function get_subject_area(): string {
        if ($this->courseconfig !== null && !empty($this->courseconfig['subjectarea'])) {
            return $this->courseconfig['subjectarea'];
        }

        return '';
    }

    /**
     * Get the pedagogical approach.
     *
     * @return string Pedagogical approach constant.
     */
    public function get_pedagogical_approach(): string {
        return $this->siteconfig['pedagogicalapproach'] ?? self::APPROACH_SOCRATIC;
    }

    /**
     * Get maximum guidance attempts before escalation.
     *
     * @return int Maximum attempts.
     */
    public function get_max_attempts(): int {
        return $this->siteconfig['maxattempts'] ?? self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Get the response language.
     *
     * @return string Language code.
     */
    public function get_response_language(): string {
        return $this->siteconfig['responselanguage'] ?? 'en';
    }

    /**
     * Get the AI temperature setting.
     *
     * @return float Temperature value.
     */
    public function get_temperature(): float {
        return $this->siteconfig['temperature'] ?? self::DEFAULT_TEMPERATURE;
    }

    /**
     * Get the maximum tokens for responses.
     *
     * @return int Maximum tokens.
     */
    public function get_max_tokens(): int {
        return $this->siteconfig['maxtokens'] ?? self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Check if conversations should be logged.
     *
     * @return bool True if logging is enabled.
     */
    public function should_log_conversations(): bool {
        return $this->siteconfig['logconversations'] ?? false;
    }

    /**
     * Get the data retention period in days.
     *
     * @return int Retention period (0 = indefinite).
     */
    public function get_retention_period(): int {
        return $this->siteconfig['retentionperiod'] ?? 0;
    }

    /**
     * Get the agent's primary goals.
     *
     * These goals define what the agent should accomplish.
     *
     * @return array List of goal strings.
     */
    public function get_agent_goals(): array {
        return [
            'help_understand' => 'Help students understand instructions, concepts, and academic expectations.',
            'promote_active_learning' => 'Promote active learning through guided questions and partial examples.',
            'reduce_frustration' => 'Reduce frustration and cognitive blocking without replacing student effort.',
            'respect_curriculum' => 'Align explanations with the configured curriculum and grade level.',
            'maintain_integrity' => 'Maintain academic integrity by never providing direct answers to assessments.',
        ];
    }

    /**
     * Get the agent's constraints (things it must NOT do).
     *
     * @return array List of constraint strings.
     */
    public function get_agent_constraints(): array {
        return [
            'no_direct_answers' => 'Never provide direct answers to assessable tasks or exercises.',
            'no_complete_solutions' => 'Never provide complete essays, code solutions, or finished work.',
            'no_grading' => 'Never evaluate, grade, or issue academic judgments.',
            'no_off_curriculum' => 'Never introduce content outside the configured curriculum or grade level.',
            'no_personal_data' => 'Never request or store sensitive personal information.',
            'no_informal_role' => 'Never adopt an informal or "friend" role.',
            'no_full_autonomy' => 'Never act with full autonomy without human oversight.',
        ];
    }

    /**
     * Build the complete context for the AI agent.
     *
     * @return array Context data for the agent.
     */
    public function build_agent_context(): array {
        return [
            'goals' => $this->get_agent_goals(),
            'constraints' => $this->get_agent_constraints(),
            'curriculum' => [
                'name' => $this->get_curriculum_name(),
                'year' => $this->get_curriculum_year(),
            ],
            'student' => [
                'grade_level' => $this->get_grade_level(),
            ],
            'course' => [
                'subject_area' => $this->get_subject_area(),
            ],
            'behaviour' => [
                'pedagogical_approach' => $this->get_pedagogical_approach(),
                'max_guidance_attempts' => $this->get_max_attempts(),
                'response_language' => $this->get_response_language(),
            ],
            'ai' => [
                'model' => $this->get_model(),
                'temperature' => $this->get_temperature(),
                'max_tokens' => $this->get_max_tokens(),
            ],
        ];
    }

    /**
     * Save course-level configuration.
     *
     * @param int $courseid Course ID.
     * @param array $config Configuration data.
     * @return bool True on success.
     */
    public static function save_course_config(int $courseid, array $config): bool {
        global $DB;

        $now = time();
        $existing = $DB->get_record('local_studentsupport_course', ['courseid' => $courseid]);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->enabled = isset($config['enabled']) ? (int) $config['enabled'] : 1;
        $record->overridecurriculum = isset($config['overridecurriculum']) ? (int) $config['overridecurriculum'] : 0;
        $record->curriculumname = $config['curriculumname'] ?? null;
        $record->gradelevel = $config['gradelevel'] ?? null;
        $record->subjectarea = $config['subjectarea'] ?? null;
        $record->customconfig = isset($config['customconfig']) ? json_encode($config['customconfig']) : null;
        $record->timemodified = $now;

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_studentsupport_course', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_studentsupport_course', $record);
        }

        // Invalidate cache.
        $coursecache = \cache::make('local_student_support', 'courseconfig');
        $coursecache->delete("course_{$courseid}");

        \cache_helper::invalidate_by_event('local_student_support_course_config_changed', [$courseid]);

        return true;
    }

    /**
     * Clear all caches.
     *
     * @return void
     */
    public static function clear_caches(): void {
        $agentcache = \cache::make('local_student_support', 'agentconfig');
        $agentcache->purge();

        $coursecache = \cache::make('local_student_support', 'courseconfig');
        $coursecache->purge();

        \cache_helper::invalidate_by_event('local_student_support_config_changed', [1]);
    }
}
