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
 *
 *  Based on   graded_users_iterator class
 * * This class iterates over all users that are graded in a course.
 * Returns detailed info about users and their grades.
 *
 * @author Petr Skoda <skodak@moodle.org>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Returns detailed info about users, their grades and class code (group).
 *
 * @package   gradeexport_xlscgs
 * @copyright 2024, Veronica Bermegui <>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir.'/gradelib.php';
require_once $CFG->dirroot.'/grade/lib.php';
/**
 * Undocumented class
 */
class graded_users_iterator_xlscgs extends graded_users_iterator {

    /**
     * The couse whose users we are interested in
     *
     * @var mixed
     */
    public $course;
    /**
     * The course where you are generating the export
     *
     * @var mixed
     */
    public $courseid;
    /**
     * An array of grade items or null if only user data was requested
     *
     * @var mixed
     */
    public $gradeitems;
    /**
     * The group ID we are interested in. 0 means all groups.
     *
     * @var mixed
     */
    public $groupid;
    /**
     * A recordset of graded users
     *
     * @var mixed
     */
    public $usersrs;
    /**
     * A recordset of user grades (grade_grade instances)
     *
     * @var mixed
     */
    public $gradesrs;
    /**
     * Array used when moving to next user while iterating through the grades recordset
     *
     * @var mixed
     */
    public $gradestack;
    /**
     * The first field of the users table by which the array of users will be sorted
     *
     * @var mixed
     */
    public $sortfield1;
    /**
     * Should sortfield1 be ASC or DESC
     *
     * @var mixed
     */
    public $sortorder1;
    /**
     * The second field of the users table by which the array of users will be sorte
     *
     * @var mixed
     */
    public $sortfield2;
    /**
     * Should sortfield2 be ASC or DESC
     *
     * @var mixed
     */
    public $sortorder2;

    /**
     * Constructor
     *
     * @param object $course A course object
     * @param int $courseid the id of the course
     * @param array  $grade_items array of grade items, if not specified only user info returned
     * @param int    $groupid iterate only group users if present
     * @param string $sortfield1 The first field of the users table by which the array of users will be sorted
     * @param string $sortorder1 The order in which the first sorting field will be sorted (ASC or DESC)
     * @param string $sortfield2 The second field of the users table by which the array of users will be sorted
     * @param string $sortorder2 The order in which the second sorting field will be sorted (ASC or DESC)
     */
    public function graded_users_iterator_xlscgs($course, $gradeitems=null, $groupid=0,
                                          $sortfield1='lastname', $sortorder1='ASC',
                                          $sortfield2='firstname', $sortorder2='ASC') {
        $this->course      = $course;
        $this->courseid    = $course->id;
        $this->grade_items = $gradeitems;
        $this->groupid     = $groupid;
        $this->sortfield1  = $sortfield1;
        $this->sortorder1  = $sortorder1;
        $this->sortfield2  = $sortfield2;
        $this->sortorder2  = $sortorder2;

        $this->gradestack  = [];
    }

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
    public function init() {
        global $CFG, $DB;

        $this->close();

        export_verify_grades($this->course->id);
        $courseitem = grade_item::fetch_course_item($this->course->id);

        if ($courseitem->needsupdate) {
            // Can not calculate all final grades - sorry.
            return false;
        }

        $coursecontext = context_course::instance($this->course->id);

        list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');
        list($gradebookrolessql, $params) = $DB->get_in_or_equal(explode(',', $CFG->gradebookroles), SQL_PARAMS_NAMED, 'grbr');
        list($enrolledsql, $enrolledparams) = get_enrolled_sql($coursecontext, '', 0, $this->onlyactive);

        $params = array_merge($params, $enrolledparams, $relatedctxparams);

        if ($this->groupid) {
            $groupsql = "INNER JOIN {groups_members} gm ON gm.userid = u.id";
            $groupwheresql = "AND gm.groupid = :groupid";
            // $params contents: gradebookroles
            $params['groupid'] = $this->groupid;

        } else {
            $groupsql = "";
            $groupwheresql = "";
        }

        if (empty($this->sortfield1)) {
            // We must do some sorting even if not specified.
            $ofields = ", u.id AS usrt";
            $order   = "usrt ASC";

        } else {
            $ofields = ", u.$this->sortfield1 AS usrt1";
            $order   = "usrt1 $this->sortorder1";
            if (!empty($this->sortfield2)) {
                $ofields .= ", u.$this->sortfield2 AS usrt2";
                $order   .= ", usrt2 $this->sortorder2";
            }
            if ($this->sortfield1 != 'id' && $this->sortfield2 != 'id') {
                // User order MUST be the same in both queries,
                // must include the only unique user->id if not already present.
                $ofields .= ", u.id AS usrt";
                $order   .= ", usrt ASC";
            }
        }

        $userfields = 'u.id, u.username, u.firstname, u.lastname, u.email';
        $customfieldssql = '';
        if ($this->allowusercustomfields && !empty($CFG->grade_export_customprofilefields)) {
            $customfieldscount = 0;
            $customfieldsarray = grade_helper::get_user_profile_fields($this->course->id, $this->allowusercustomfields);
            foreach ($customfieldsarray as $field) {
                if (!empty($field->customid)) {
                    $customfieldssql .= "
                            LEFT JOIN (SELECT * FROM {user_info_data}
                                WHERE fieldid = :cf$customfieldscount) cf$customfieldscount
                            ON u.id = cf$customfieldscount.userid";
                    $userfields .= ", cf$customfieldscount.data AS customfield_{$field->customid}";
                    $params['cf'.$customfieldscount] = $field->customid;
                    $customfieldscount++;
                }
            }
        }

        $userssql = "SELECT $userfields $ofields, gr.id AS groupid, gr.name AS groupname
              FROM {user} u
              JOIN ($enrolledsql) je ON je.id = u.id
              $groupsql
              $customfieldssql
              JOIN (
                    SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    WHERE ra.roleid $gradebookrolessql
                    AND ra.contextid $relatedctxsql
                   ) rainner ON rainner.userid = u.id
              INNER JOIN {groups_members} gm ON gm.userid = u.id
              INNER JOIN {groups} gr ON gr.id = gm.groupid
              WHERE u.deleted = 0
              AND gr.courseid = :courseid
              $groupwheresql
              ORDER BY $order";

        $params['courseid'] = $this->course->id;

        $this->users_rs = $DB->get_recordset_sql($userssql, $params);

        if (!$this->onlyactive) {
            $context = context_course::instance($this->course->id);
            $this->suspendedusers = get_suspended_userids($context);
        } else {
            $this->suspendedusers = [];
        }

        if (!empty($this->grade_items)) {
            $itemids = array_keys($this->grade_items);
            list($itemidsql, $gradesparams) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'items');
            $params = array_merge($params, $gradesparams);
            error_log(print_r($itemidsql, true));
            error_log(print_r($gradesparams, true));
            error_log(print_r( $params, true));

            $gradessql = "SELECT g.* $ofields
                             FROM {grade_grades} g
                             JOIN {user} u ON g.userid = u.id
                             JOIN ($enrolledsql) je ON je.id = u.id
                                  $groupsql
                             JOIN (
                                      SELECT DISTINCT ra.userid
                                        FROM {role_assignments} ra
                                       WHERE ra.roleid $gradebookrolessql
                                         AND ra.contextid $relatedctxsql
                                  ) rainner ON rainner.userid = u.id
                              WHERE u.deleted = 0
                              AND g.itemid $itemidsql
                              $groupwheresql
                         ORDER BY $order, g.itemid ASC";
            $this->grades_rs = $DB->get_recordset_sql($gradessql, $params);
        } else {
            $this->grades_rs = false;
        }

        return true;
    }


}
