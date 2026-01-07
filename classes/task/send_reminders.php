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

/**
 * Reminder email task for completion progress.
 *
 * @package    block_completion_progress
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_completion_progress\task;

use block_completion_progress\completion_progress;
use block_completion_progress\defaults;
use context_course;
use core_user;
use moodle_url;

defined('MOODLE_INTERNAL') || die;

/**
 * Scheduled task that sends reminder emails to students below the configured threshold.
 */
class send_reminders extends \core\task\scheduled_task {
    /**
     * Task name for display.
     * @return string
     */
    public function get_name() {
        return get_string('task_sendreminders', 'block_completion_progress');
    }

    /**
     * Run the reminder task.
     */
    public function execute() {
        global $CFG, $DB;

        if (empty($CFG->enablecompletion)) {
            return;
        }

        require_once($CFG->dirroot . '/group/lib.php');

        $instances = $DB->get_records('block_instances', ['blockname' => 'completion_progress']);
        if (!$instances) {
            return;
        }

        foreach ($instances as $instance) {
            $parentcontext = \context::instance_by_id($instance->parentcontextid, IGNORE_MISSING);
            if (!$parentcontext || $parentcontext->contextlevel !== CONTEXT_COURSE) {
                mtrace("Skipping block {$instance->id}: not in course context.");
                continue;
            }
            if (!$this->is_instance_visible((int)$instance->id, (int)$parentcontext->id)) {
                mtrace("Skipping block {$instance->id}: not visible in course context.");
                continue;
            }

            $courseid = $parentcontext->instanceid;
            $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
            if (!$course) {
                mtrace("Skipping block {$instance->id}: course not found for {$courseid}.");
                continue;
            }
            mtrace("Block {$instance->id}: course {$courseid}.");

            $blockcontext = \context_block::instance($instance->id);

            $blockconfig = (object)(array)unserialize(base64_decode($instance->configdata ?? ''));
            if (empty($blockconfig->reminderenabled)) {
                mtrace("Skipping block {$instance->id}: reminders disabled.");
                continue;
            }

            $frequency = $blockconfig->reminderfrequency ?? defaults::REMINDERFREQUENCY;
            $interval = $this->frequency_to_seconds($frequency);
            $lastsent = isset($blockconfig->reminderlastsent) ? (int)$blockconfig->reminderlastsent : 0;
            if ($interval > 0 && $lastsent > 0 && (time() - $lastsent) < $interval) {
                $remaining = $interval - (time() - $lastsent);
                mtrace("Skipping block {$instance->id}: frequency {$frequency}, wait {$remaining}s.");
                continue;
            }

            $threshold = isset($blockconfig->reminderthreshold) ? (int)$blockconfig->reminderthreshold :
                defaults::REMINDERTHRESHOLD;
            $threshold = max(0, min(100, $threshold));
            mtrace("Block {$instance->id}: threshold {$threshold}, frequency {$frequency}.");

            $coursecontext = context_course::instance($courseid);
            $progressbase = (new completion_progress($course))->for_block_instance($instance);
            if (!$progressbase->get_completion_info()->is_enabled()) {
                mtrace("Skipping block {$instance->id}: completion disabled in course.");
                continue;
            }
            if (!$progressbase->has_activities()) {
                mtrace("Skipping block {$instance->id}: no activities.");
                continue;
            }

            $users = get_enrolled_users($coursecontext, '', 0, 'u.*', '', 0, 0, true);
            if (!$users) {
                mtrace("Skipping block {$instance->id}: no enrolled users.");
                continue;
            }
            mtrace("Block {$instance->id}: enrolled users " . count($users) . ".");

            $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
            $coursename = format_string($course->fullname, true, ['context' => $coursecontext]);
            $sentany = false;

            foreach ($users as $user) {
                if (!has_capability('block/completion_progress:showbar', $blockcontext, $user->id)) {
                    mtrace("Block {$instance->id}: user {$user->id} skipped: no showbar capability.");
                    continue;
                }
                if (has_capability('block/completion_progress:overview', $blockcontext, $user->id)) {
                    mtrace("Block {$instance->id}: user {$user->id} skipped: has overview capability.");
                    continue;
                }
                if (!$this->user_matches_group($blockconfig->group ?? '0', $courseid, $user->id)) {
                    mtrace("Block {$instance->id}: user {$user->id} skipped: not in group filter.");
                    continue;
                }

                $progress = clone $progressbase;
                $progress->for_user($user);
                if (!$progress->has_visible_activities()) {
                    mtrace("Block {$instance->id}: user {$user->id} skipped: no visible activities.");
                    continue;
                }

                $percent = $progress->get_percentage();
                if ($percent === null || $percent >= $threshold) {
                    $val = $percent === null ? 'null' : (string)$percent;
                    mtrace("Block {$instance->id}: user {$user->id} skipped: percent {$val}.");
                    continue;
                }

                $data = (object)[
                    'firstname' => $user->firstname,
                    'coursename' => $coursename,
                    'percent' => $percent,
                    'courseurl' => $courseurl->out(false),
                ];
                $subject = get_string('reminderemailsubject', 'block_completion_progress', $data);
                $plain = get_string('reminderemailbody', 'block_completion_progress', $data);
                $html = text_to_html($plain, false, false, true);
                $sent = email_to_user($user, core_user::get_support_user(), $subject, $plain, $html);
                $status = $sent ? 'sent' : 'failed';
                mtrace("Block {$instance->id}: user {$user->id} email {$status}.");
                if ($sent) {
                    $sentany = true;
                }
            }

            if ($sentany) {
                $blockconfig->reminderlastsent = time();
                $instance->configdata = base64_encode(serialize((array)$blockconfig));
                $DB->update_record('block_instances', $instance);
                mtrace("Block {$instance->id}: reminder last sent updated.");
            } else {
                mtrace("Block {$instance->id}: no emails sent, last sent unchanged.");
            }
        }
    }

    /**
     * Determine whether a block instance is visible on course pages.
     * @param int $instanceid
     * @param int $contextid
     * @return bool
     */
    private function is_instance_visible(int $instanceid, int $contextid): bool {
        global $DB;

        $sql = "SELECT bp.visible
                  FROM {block_positions} bp
                 WHERE bp.blockinstanceid = :instanceid
                   AND bp.contextid = :contextid
                   AND " . $DB->sql_like('bp.pagetype', ':pagetype', false);
        $params = [
            'instanceid' => $instanceid,
            'contextid' => $contextid,
            'pagetype' => 'course-view-%',
        ];
        $visibles = $DB->get_fieldset_sql($sql, $params);
        if (!$visibles) {
            return true;
        }
        foreach ($visibles as $visible) {
            if ((int)$visible === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert frequency settings into seconds.
     * @param string $frequency
     * @return int
     */
    private function frequency_to_seconds(string $frequency): int {
        switch ($frequency) {
            case 'daily':
                return DAYSECS;
            case 'weekly':
                return WEEKSECS;
            case 'monthly':
                return 30 * DAYSECS;
            case 'yearly':
                return 365 * DAYSECS;
            default:
                return 0;
        }
    }

    /**
     * Check if a user belongs to the configured group or grouping.
     * @param string $group
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function user_matches_group(string $group, int $courseid, int $userid): bool {
        if ($group === '0' || $group === '') {
            return true;
        }
        if ((substr($group, 0, 6) === 'group-') && ($groupid = (int)substr($group, 6))) {
            return groups_is_member($groupid, $userid);
        }
        if ((substr($group, 0, 9) === 'grouping-') && ($groupingid = (int)substr($group, 9))) {
            $usergroups = groups_get_user_groups($courseid, $userid);
            return array_key_exists($groupingid, $usergroups);
        }

        return true;
    }
}
