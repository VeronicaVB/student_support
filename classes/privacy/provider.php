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

namespace local_student_support\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for local_student_support.
 *
 * @package   local_student_support
 * @copyright 2025, Veronica Bermegui
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        // Messages table.
        $collection->add_database_table(
            'local_studentsupport_messages',
            [
                'userid' => 'privacy:metadata:conversations:userid',
                'content' => 'privacy:metadata:conversations:message',
                'timecreated' => 'privacy:metadata:conversations:timecreated',
            ],
            'privacy:metadata:conversations'
        );

        // State table.
        $collection->add_database_table(
            'local_studentsupport_state',
            [
                'userid' => 'privacy:metadata:state:userid',
                'statedata' => 'privacy:metadata:state:statedata',
            ],
            'privacy:metadata:state'
        );

        // Summary table.
        $collection->add_database_table(
            'local_studentsupport_summary',
            [
                'userid' => 'privacy:metadata:conversations:userid',
                'summary' => 'privacy:metadata:conversations:message',
                'timecreated' => 'privacy:metadata:conversations:timecreated',
            ],
            'privacy:metadata:conversations'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information.
     *
     * @param int $userid The user ID.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Get course contexts from messages.
        $sql = "SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {local_studentsupport_messages} m ON m.courseid = c.instanceid
                 WHERE c.contextlevel = :contextlevel
                   AND m.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT DISTINCT userid
                  FROM {local_studentsupport_messages}
                 WHERE courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Export messages.
            $messages = $DB->get_records(
                'local_studentsupport_messages',
                ['userid' => $userid, 'courseid' => $courseid],
                'timecreated ASC'
            );

            if (!empty($messages)) {
                $data = [];
                foreach ($messages as $message) {
                    $data[] = [
                        'role' => $message->role,
                        'content' => $message->content,
                        'timecreated' => transform::datetime($message->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_student_support'), 'conversations'],
                    (object) ['messages' => $data]
                );
            }

            // Export summaries.
            $summaries = $DB->get_records(
                'local_studentsupport_summary',
                ['userid' => $userid, 'courseid' => $courseid],
                'timecreated ASC'
            );

            if (!empty($summaries)) {
                $data = [];
                foreach ($summaries as $summary) {
                    $data[] = [
                        'summary' => $summary->summary,
                        'topics' => $summary->topics,
                        'outcome' => $summary->outcome,
                        'timecreated' => transform::datetime($summary->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_student_support'), 'summaries'],
                    (object) ['summaries' => $data]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;

        // Get all session IDs for this course.
        $sessionids = $DB->get_fieldset_select(
            'local_studentsupport_messages',
            'DISTINCT sessionid',
            'courseid = :courseid',
            ['courseid' => $courseid]
        );

        if (!empty($sessionids)) {
            list($insql, $params) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED);

            $DB->delete_records_select('local_studentsupport_messages', "sessionid {$insql}", $params);
            $DB->delete_records_select('local_studentsupport_state', "sessionid {$insql}", $params);
            $DB->delete_records_select('local_studentsupport_summary', "sessionid {$insql}", $params);
        }
    }

    /**
     * Delete all user data for the specified user in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Get session IDs for this user in this course.
            $sessionids = $DB->get_fieldset_select(
                'local_studentsupport_messages',
                'DISTINCT sessionid',
                'userid = :userid AND courseid = :courseid',
                ['userid' => $userid, 'courseid' => $courseid]
            );

            if (!empty($sessionids)) {
                list($insql, $params) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED);

                $DB->delete_records_select('local_studentsupport_messages', "sessionid {$insql}", $params);
                $DB->delete_records_select('local_studentsupport_state', "sessionid {$insql}", $params);
                $DB->delete_records_select('local_studentsupport_summary', "sessionid {$insql}", $params);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved userlist.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Get session IDs for these users in this course.
        $sql = "SELECT DISTINCT sessionid
                  FROM {local_studentsupport_messages}
                 WHERE userid {$usersql}
                   AND courseid = :courseid";

        $params = array_merge($userparams, ['courseid' => $courseid]);
        $sessionids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($sessionids)) {
            list($insql, $params) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED);

            $DB->delete_records_select('local_studentsupport_messages', "sessionid {$insql}", $params);
            $DB->delete_records_select('local_studentsupport_state', "sessionid {$insql}", $params);
            $DB->delete_records_select('local_studentsupport_summary', "sessionid {$insql}", $params);
        }
    }
}
