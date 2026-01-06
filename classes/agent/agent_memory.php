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
 * Agent memory class.
 *
 * Manages the agent's state and memory during conversations.
 * This includes:
 * - Conversation state tracking
 * - Message history management
 * - Guidance attempt counting
 * - Topic tracking
 * - Persistence (cache + database)
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_memory {

    /** @var string State: New conversation. */
    public const STATE_NEW = 'new';

    /** @var string State: Understanding the student's question. */
    public const STATE_UNDERSTANDING = 'understanding';

    /** @var string State: Providing guidance. */
    public const STATE_GUIDING = 'guiding';

    /** @var string State: Checking student comprehension. */
    public const STATE_CHECKING = 'checking';

    /** @var string State: Suggesting teacher escalation. */
    public const STATE_ESCALATING = 'escalating';

    /** @var string State: Conversation completed. */
    public const STATE_COMPLETED = 'completed';

    /** @var string Message role: User. */
    public const ROLE_USER = 'user';

    /** @var string Message role: Assistant. */
    public const ROLE_ASSISTANT = 'assistant';

    /** @var string Message role: System. */
    public const ROLE_SYSTEM = 'system';

    /** @var int Maximum messages to keep in active context. */
    private const MAX_CONTEXT_MESSAGES = 20;

    /** @var string The session ID. */
    private string $sessionid;

    /** @var int The user ID. */
    private int $userid;

    /** @var int The course ID. */
    private int $courseid;

    /** @var string Current state. */
    private string $currentstate;

    /** @var int Number of guidance attempts for current topic. */
    private int $guidanceattempts;

    /** @var string|null Current topic being discussed. */
    private ?string $currenttopic;

    /** @var string|null Detected intent of the last user message. */
    private ?string $lastintent;

    /** @var string|null Last action taken by the agent. */
    private ?string $lastaction;

    /** @var array Additional state data. */
    private array $statedata;

    /** @var array Message history for current session. */
    private array $messages;

    /** @var \cache Session cache instance. */
    private \cache $cache;

    /** @var bool Whether state has been modified. */
    private bool $dirty;

    /**
     * Constructor.
     *
     * @param string $sessionid Session identifier.
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     */
    public function __construct(string $sessionid, int $userid, int $courseid) {
        $this->sessionid = $sessionid;
        $this->userid = $userid;
        $this->courseid = $courseid;
        $this->cache = \cache::make('local_student_support', 'conversationstate');
        $this->dirty = false;

        $this->load_state();
    }

    /**
     * Generate a new session ID.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     * @return string New session ID.
     */
    public static function generate_session_id(int $userid, int $courseid): string {
        return sprintf(
            '%d_%d_%s',
            $userid,
            $courseid,
            bin2hex(random_bytes(16))
        );
    }

    /**
     * Load state from cache or database.
     *
     * @return void
     */
    private function load_state(): void {
        $cached = $this->cache->get($this->sessionid);

        if ($cached !== false) {
            $this->restore_from_array($cached);
            return;
        }

        $dbstate = $this->load_from_database();

        if ($dbstate !== null) {
            $this->restore_from_array($dbstate);
            $this->cache->set($this->sessionid, $dbstate);
            return;
        }

        $this->initialize_new_state();
    }

    /**
     * Load state from database.
     *
     * @return array|null State data or null if not found.
     */
    private function load_from_database(): ?array {
        global $DB;

        $record = $DB->get_record('local_studentsupport_state', ['sessionid' => $this->sessionid]);

        if (!$record) {
            return null;
        }

        $messages = $DB->get_records(
            'local_studentsupport_messages',
            ['sessionid' => $this->sessionid],
            'timecreated ASC'
        );

        $messagearray = [];
        foreach ($messages as $msg) {
            $messagearray[] = [
                'role' => $msg->role,
                'content' => $msg->content,
                'metadata' => $msg->metadata ? json_decode($msg->metadata, true) : [],
                'timecreated' => $msg->timecreated,
            ];
        }

        $statedata = $record->statedata ? json_decode($record->statedata, true) : [];

        return [
            'sessionid' => $record->sessionid,
            'userid' => (int) $record->userid,
            'courseid' => (int) $record->courseid,
            'currentstate' => $record->currentstate,
            'guidanceattempts' => (int) $record->guidanceattempts,
            'currenttopic' => $record->currenttopic,
            'lastintent' => $statedata['lastintent'] ?? null,
            'lastaction' => $statedata['lastaction'] ?? null,
            'statedata' => $statedata,
            'messages' => $messagearray,
        ];
    }

    /**
     * Initialize a new conversation state.
     *
     * @return void
     */
    private function initialize_new_state(): void {
        $this->currentstate = self::STATE_NEW;
        $this->guidanceattempts = 0;
        $this->currenttopic = null;
        $this->lastintent = null;
        $this->lastaction = null;
        $this->statedata = [];
        $this->messages = [];
        $this->dirty = true;
    }

    /**
     * Restore state from array.
     *
     * @param array $data State data array.
     * @return void
     */
    private function restore_from_array(array $data): void {
        $this->currentstate = $data['currentstate'] ?? self::STATE_NEW;
        $this->guidanceattempts = $data['guidanceattempts'] ?? 0;
        $this->currenttopic = $data['currenttopic'] ?? null;
        $this->lastintent = $data['lastintent'] ?? null;
        $this->lastaction = $data['lastaction'] ?? null;
        $this->statedata = $data['statedata'] ?? [];
        $this->messages = $data['messages'] ?? [];
    }

    /**
     * Convert state to array for storage.
     *
     * @return array State data array.
     */
    private function to_array(): array {
        return [
            'sessionid' => $this->sessionid,
            'userid' => $this->userid,
            'courseid' => $this->courseid,
            'currentstate' => $this->currentstate,
            'guidanceattempts' => $this->guidanceattempts,
            'currenttopic' => $this->currenttopic,
            'lastintent' => $this->lastintent,
            'lastaction' => $this->lastaction,
            'statedata' => $this->statedata,
            'messages' => $this->messages,
        ];
    }

    /**
     * Get the session ID.
     *
     * @return string Session ID.
     */
    public function get_session_id(): string {
        return $this->sessionid;
    }

    /**
     * Get the user ID.
     *
     * @return int User ID.
     */
    public function get_user_id(): int {
        return $this->userid;
    }

    /**
     * Get the course ID.
     *
     * @return int Course ID.
     */
    public function get_course_id(): int {
        return $this->courseid;
    }

    /**
     * Get the current state.
     *
     * @return string Current state constant.
     */
    public function get_current_state(): string {
        return $this->currentstate;
    }

    /**
     * Set the current state.
     *
     * @param string $state State constant.
     * @return void
     */
    public function set_current_state(string $state): void {
        $validstates = [
            self::STATE_NEW,
            self::STATE_UNDERSTANDING,
            self::STATE_GUIDING,
            self::STATE_CHECKING,
            self::STATE_ESCALATING,
            self::STATE_COMPLETED,
        ];

        if (!in_array($state, $validstates, true)) {
            throw new \coding_exception("Invalid state: {$state}");
        }

        $this->currentstate = $state;
        $this->dirty = true;
    }

    /**
     * Get the number of guidance attempts.
     *
     * @return int Guidance attempts count.
     */
    public function get_guidance_attempts(): int {
        return $this->guidanceattempts;
    }

    /**
     * Increment guidance attempts.
     *
     * @return int New attempts count.
     */
    public function increment_guidance_attempts(): int {
        $this->guidanceattempts++;
        $this->dirty = true;
        return $this->guidanceattempts;
    }

    /**
     * Reset guidance attempts (e.g., when topic changes).
     *
     * @return void
     */
    public function reset_guidance_attempts(): void {
        $this->guidanceattempts = 0;
        $this->dirty = true;
    }

    /**
     * Get the current topic.
     *
     * @return string|null Current topic or null.
     */
    public function get_current_topic(): ?string {
        return $this->currenttopic;
    }

    /**
     * Set the current topic.
     *
     * @param string|null $topic Topic name or null.
     * @return void
     */
    public function set_current_topic(?string $topic): void {
        // If topic changed, reset attempts and track in discussed topics.
        if ($topic !== $this->currenttopic && $this->currenttopic !== null) {
            $discussedtopics = $this->get_data('discussed_topics', []);
            if (!in_array($this->currenttopic, $discussedtopics, true)) {
                $discussedtopics[] = $this->currenttopic;
                $this->set_data('discussed_topics', $discussedtopics);
            }
            $this->reset_guidance_attempts();
        }

        $this->currenttopic = $topic;
        $this->dirty = true;
    }

    /**
     * Get the last detected intent.
     *
     * @return string|null Last intent or null.
     */
    public function get_last_intent(): ?string {
        return $this->lastintent;
    }

    /**
     * Set the last detected intent.
     *
     * @param string|null $intent Intent identifier.
     * @return void
     */
    public function set_last_intent(?string $intent): void {
        $this->lastintent = $intent;
        $this->dirty = true;
    }

    /**
     * Get the last action taken.
     *
     * @return string|null Last action or null.
     */
    public function get_last_action(): ?string {
        return $this->lastaction;
    }

    /**
     * Set the last action taken.
     *
     * @param string|null $action Action identifier.
     * @return void
     */
    public function set_last_action(?string $action): void {
        $this->lastaction = $action;
        $this->dirty = true;
    }

    /**
     * Get a value from state data.
     *
     * @param string $key Key name.
     * @param mixed $default Default value.
     * @return mixed Value or default.
     */
    public function get_data(string $key, $default = null) {
        return $this->statedata[$key] ?? $default;
    }

    /**
     * Set a value in state data.
     *
     * @param string $key Key name.
     * @param mixed $value Value to store.
     * @return void
     */
    public function set_data(string $key, $value): void {
        $this->statedata[$key] = $value;
        $this->dirty = true;
    }

    /**
     * Add a message to the conversation.
     *
     * @param string $role Message role (user, assistant, system).
     * @param string $content Message content.
     * @param array $metadata Optional metadata.
     * @return void
     */
    public function add_message(string $role, string $content, array $metadata = []): void {
        $validroles = [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_SYSTEM];

        if (!in_array($role, $validroles, true)) {
            throw new \coding_exception("Invalid message role: {$role}");
        }

        $this->messages[] = [
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'timecreated' => time(),
        ];

        $this->dirty = true;
    }

    /**
     * Get all messages in the conversation.
     *
     * @return array Array of messages.
     */
    public function get_messages(): array {
        return $this->messages;
    }

    /**
     * Get messages formatted for AI context.
     *
     * @return array Messages formatted for AI API.
     */
    public function get_context_messages(): array {
        $messages = $this->messages;

        if (count($messages) > self::MAX_CONTEXT_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_CONTEXT_MESSAGES);
        }

        return array_map(function ($msg) {
            return [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }, $messages);
    }

    /**
     * Get the message count.
     *
     * @return int Number of messages.
     */
    public function get_message_count(): int {
        return count($this->messages);
    }

    /**
     * Get the last message.
     *
     * @return array|null Last message or null.
     */
    public function get_last_message(): ?array {
        if (empty($this->messages)) {
            return null;
        }

        return end($this->messages);
    }

    /**
     * Get the last user message.
     *
     * @return array|null Last user message or null.
     */
    public function get_last_user_message(): ?array {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === self::ROLE_USER) {
                return $this->messages[$i];
            }
        }

        return null;
    }

    /**
     * Check if the conversation should escalate to teacher.
     *
     * @param int $maxattempts Maximum attempts before escalation.
     * @return bool True if should escalate.
     */
    public function should_escalate(int $maxattempts): bool {
        return $this->guidanceattempts >= $maxattempts;
    }

    /**
     * Check if this is a new conversation.
     *
     * @return bool True if new conversation.
     */
    public function is_new_conversation(): bool {
        return $this->currentstate === self::STATE_NEW && empty($this->messages);
    }

    /**
     * Get a summary of the current memory state for the agent.
     *
     * @return array Memory summary.
     */
    public function get_memory_summary(): array {
        return [
            'session_id' => $this->sessionid,
            'state' => $this->currentstate,
            'current_topic' => $this->currenttopic,
            'guidance_attempts' => $this->guidanceattempts,
            'last_intent' => $this->lastintent,
            'last_action' => $this->lastaction,
            'message_count' => count($this->messages),
            'discussed_topics' => $this->get_data('discussed_topics', []),
        ];
    }

    /**
     * Save the state to cache and optionally to database.
     *
     * @param bool $persist Whether to persist to database.
     * @return void
     */
    public function save(bool $persist = false): void {
        if (!$this->dirty) {
            return;
        }

        // Include intent and action in statedata for persistence.
        $this->statedata['lastintent'] = $this->lastintent;
        $this->statedata['lastaction'] = $this->lastaction;

        $data = $this->to_array();

        $this->cache->set($this->sessionid, $data);

        if ($persist) {
            $this->save_to_database();
        }

        $this->dirty = false;
    }

    /**
     * Save state to database.
     *
     * @return void
     */
    private function save_to_database(): void {
        global $DB;

        $now = time();

        $existing = $DB->get_record('local_studentsupport_state', ['sessionid' => $this->sessionid]);

        $record = new \stdClass();
        $record->sessionid = $this->sessionid;
        $record->userid = $this->userid;
        $record->courseid = $this->courseid;
        $record->currentstate = $this->currentstate;
        $record->guidanceattempts = $this->guidanceattempts;
        $record->currenttopic = $this->currenttopic;
        $record->statedata = json_encode($this->statedata);
        $record->timemodified = $now;

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_studentsupport_state', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_studentsupport_state', $record);
        }

        $this->save_new_messages();
    }

    /**
     * Save new messages to database.
     *
     * @return void
     */
    private function save_new_messages(): void {
        global $DB;

        $existingcount = $DB->count_records('local_studentsupport_messages', ['sessionid' => $this->sessionid]);
        $newmessages = array_slice($this->messages, $existingcount);

        foreach ($newmessages as $msg) {
            $record = new \stdClass();
            $record->sessionid = $this->sessionid;
            $record->userid = $this->userid;
            $record->courseid = $this->courseid;
            $record->role = $msg['role'];
            $record->content = $msg['content'];
            $record->metadata = !empty($msg['metadata']) ? json_encode($msg['metadata']) : null;
            $record->timecreated = $msg['timecreated'];

            $DB->insert_record('local_studentsupport_messages', $record);
        }
    }

    /**
     * Clear the conversation and start fresh.
     *
     * @param bool $deletefromdb Whether to delete from database.
     * @return void
     */
    public function clear(bool $deletefromdb = false): void {
        global $DB;

        $this->initialize_new_state();
        $this->cache->delete($this->sessionid);

        if ($deletefromdb) {
            $DB->delete_records('local_studentsupport_messages', ['sessionid' => $this->sessionid]);
            $DB->delete_records('local_studentsupport_state', ['sessionid' => $this->sessionid]);
        }
    }

    /**
     * Save a summary of the conversation.
     *
     * @param string $summary The summary text.
     * @param string|null $outcome The conversation outcome.
     * @return void
     */
    public function save_summary(string $summary, ?string $outcome = null): void {
        global $DB;

        $topics = $this->get_data('discussed_topics', []);
        if ($this->currenttopic && !in_array($this->currenttopic, $topics, true)) {
            $topics[] = $this->currenttopic;
        }

        $record = new \stdClass();
        $record->sessionid = $this->sessionid;
        $record->userid = $this->userid;
        $record->courseid = $this->courseid;
        $record->summary = $summary;
        $record->topics = json_encode($topics);
        $record->outcome = $outcome;
        $record->messagecount = count($this->messages);
        $record->timecreated = time();

        $existing = $DB->get_record('local_studentsupport_summary', ['sessionid' => $this->sessionid]);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_studentsupport_summary', $record);
        } else {
            $DB->insert_record('local_studentsupport_summary', $record);
        }
    }

    /**
     * Get conversation history for a user in a course.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     * @param int $limit Maximum records to return.
     * @return array Array of conversation summaries.
     */
    public static function get_user_history(int $userid, int $courseid, int $limit = 10): array {
        global $DB;

        $records = $DB->get_records(
            'local_studentsupport_summary',
            ['userid' => $userid, 'courseid' => $courseid],
            'timecreated DESC',
            '*',
            0,
            $limit
        );

        $history = [];
        foreach ($records as $record) {
            $history[] = [
                'sessionid' => $record->sessionid,
                'summary' => $record->summary,
                'topics' => json_decode($record->topics, true) ?? [],
                'outcome' => $record->outcome,
                'messagecount' => (int) $record->messagecount,
                'timecreated' => (int) $record->timecreated,
            ];
        }

        return $history;
    }

    /**
     * Delete old conversation data based on retention period.
     *
     * @param int $retentiondays Number of days to retain (0 = no deletion).
     * @return int Number of sessions deleted.
     */
    public static function cleanup_old_data(int $retentiondays): int {
        global $DB;

        if ($retentiondays <= 0) {
            return 0;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        $sql = "SELECT DISTINCT sessionid
                  FROM {local_studentsupport_messages}
                 WHERE timecreated < :cutoff";

        $sessions = $DB->get_fieldset_sql($sql, ['cutoff' => $cutoff]);

        if (empty($sessions)) {
            return 0;
        }

        foreach ($sessions as $sessionid) {
            $DB->delete_records('local_studentsupport_messages', ['sessionid' => $sessionid]);
            $DB->delete_records('local_studentsupport_state', ['sessionid' => $sessionid]);
            $DB->delete_records('local_studentsupport_summary', ['sessionid' => $sessionid]);
        }

        return count($sessions);
    }
}
