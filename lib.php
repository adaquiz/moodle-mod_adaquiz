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
 * Library of functions for the quiz module.
 *
 * This contains functions that are called also from outside the quiz module
 * Functions that are only called by the quiz module itself are in {@link locallib.php}
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/calendar/lib.php');


/**#@+
 * Option controlling what options are offered on the adaptive quiz settings form.
 */
define('ADAQUIZ_MAX_ATTEMPT_OPTION', 10);
define('ADAQUIZ_MAX_QPP_OPTION', 50);
define('ADAQUIZ_MAX_DECIMAL_OPTION', 5);
define('ADAQUIZ_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('ADAQUIZ_GRADEHIGHEST', '1');
define('ADAQUIZ_GRADEAVERAGE', '2');
define('ADAQUIZ_ATTEMPTFIRST', '3');
define('ADAQUIZ_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the adaptive quiz are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('ADAQUIZ_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within adaptive quizzes.
 */
define('ADAQUIZ_NAVMETHOD_FREE', 'free');
define('ADAQUIZ_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $adaquiz the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function adaquiz_add_instance($adaquiz) {
    global $DB;
    $cmid = $adaquiz->coursemodule;

    // Process the options from the form.
    $adaquiz->created = time();
    $result = adaquiz_process_options($adaquiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $adaquiz->id = $DB->insert_record('adaquiz', $adaquiz);

    // Do the processing required after an add or an update.
    adaquiz_after_add_or_update($adaquiz);

    return $adaquiz->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $adaquiz the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function adaquiz_update_instance($adaquiz, $mform) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

    // Process the options from the form.
    $result = adaquiz_process_options($adaquiz);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldadaquiz = $DB->get_record('adaquiz', array('id' => $adaquiz->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $adaquiz->sumgrades = $oldadaquiz->sumgrades;
    $adaquiz->grade     = $oldadaquiz->grade;

    // Update the database.
    $adaquiz->id = $adaquiz->instance;
    $DB->update_record('adaquiz', $adaquiz);

    // Do the processing required after an add or an update.
    adaquiz_after_add_or_update($adaquiz);

    if ($oldadaquiz->grademethod != $adaquiz->grademethod) {
        adaquiz_update_all_final_grades($adaquiz);
        adaquiz_update_grades($adaquiz);
    }

    $adaquizdateschanged = $oldadaquiz->timelimit   != $adaquiz->timelimit
                     || $oldadaquiz->timeclose   != $adaquiz->timeclose
                     || $oldadaquiz->graceperiod != $adaquiz->graceperiod;
    if ($adaquizdateschanged) {
        adaquiz_update_open_attempts(array('adaquizid' => $adaquiz->id));
    }

    // Delete any previous preview attempts.
    adaquiz_delete_previews($adaquiz);

    // Repaginate, if asked to.
    if (!$adaquiz->shufflequestions && !empty($adaquiz->repaginatenow)) {
        adaquiz_repaginate_questions($adaquiz->id, $adaquiz->questionsperpage);
    }

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the adaptive quiz to delete.
 * @return bool success or failure.
 */
function adaquiz_delete_instance($id) {
    global $DB;

    $adaquiz = $DB->get_record('adaquiz', array('id' => $id), '*', MUST_EXIST);

    adaquiz_delete_all_attempts($adaquiz);
    adaquiz_delete_all_overrides($adaquiz);

    // Look for random questions that may no longer be used when this adaptive quiz is gone.
    $sql = "SELECT q.id
              FROM {adaquiz_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.adaquizid = ? AND q.qtype = ?";
    $questionids = $DB->get_fieldset_sql($sql, array($adaquiz->id, 'random'));

    // We need to do this before we try and delete randoms, otherwise they would still be 'in use'.
    $DB->delete_records('adaquiz_slots', array('adaquizid' => $adaquiz->id));

    foreach ($questionids as $questionid) {
        question_delete_question($questionid);
    }

    $DB->delete_records('adaquiz_feedback', array('adaquizid' => $adaquiz->id));

    adaquiz_access_manager::delete_settings($adaquiz);

    $events = $DB->get_records('event', array('modulename' => 'adaquiz', 'instance' => $adaquiz->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    adaquiz_grade_item_delete($adaquiz);
    $DB->delete_records('adaquiz', array('id' => $adaquiz->id));

    return true;
}

/**
 * Deletes an adaptive quiz override from the database and clears any corresponding calendar events
 *
 * @param object $adaquiz The adaptive quiz object.
 * @param int $overrideid The id of the override being deleted
 * @return bool true on success
 */
function adaquiz_delete_override($adaquiz, $overrideid) {
    global $DB;

    if (!isset($adaquiz->cmid)) {
        $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id, $adaquiz->course);
        $adaquiz->cmid = $cm->id;
    }

    $override = $DB->get_record('adaquiz_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    $events = $DB->get_records('event', array('modulename' => 'adaquiz',
            'instance' => $adaquiz->id, 'groupid' => (int)$override->groupid,
            'userid' => (int)$override->userid));
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('adaquiz_overrides', array('id' => $overrideid));

    // Set the common parameters for one of the events we will be triggering.
    $params = array(
        'objectid' => $override->id,
        'context' => context_module::instance($adaquiz->cmid),
        'other' => array(
            'adaquizid' => $override->quiz
        )
    );
    // Determine which override deleted event to fire.
    if (!empty($override->userid)) {
        $params['relateduserid'] = $override->userid;
        $event = \mod_adaquiz\event\user_override_deleted::create($params);
    } else {
        $params['other']['groupid'] = $override->groupid;
        $event = \mod_adaquiz\event\group_override_deleted::create($params);
    }

    // Trigger the override deleted event.
    $event->add_record_snapshot('adaquiz_overrides', $override);
    $event->trigger();

    return true;
}

/**
 * Deletes all adaptive quiz overrides from the database and clears any corresponding calendar events
 *
 * @param object $adaquiz The adaptive quiz object.
 */
function adaquiz_delete_all_overrides($adaquiz) {
    global $DB;

    $overrides = $DB->get_records('adaquiz_overrides', array('quiz' => $adaquiz->id), 'id');
    foreach ($overrides as $override) {
        adaquiz_delete_override($adaquiz, $override->id);
    }
}

/**
 * Updates an adaptive quiz object with override information for a user.
 *
 * Algorithm:  For each adaptive quiz setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the adaptive quiz setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   adaquiz->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $adaquiz The adaptive quiz object.
 * @param int $userid The userid.
 * @return object $adaquiz The updated  adaptive quiz object.
 */
function adaquiz_update_effective_access($adaquiz, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('adaquiz_overrides', array('quiz' => $adaquiz->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($adaquiz->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {adaquiz_overrides}
                WHERE groupid $extra AND quiz = ?";
        $params[] = $adaquiz->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with adaptive quiz defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $adaquiz->{$key} = $override->{$key};
        }
    }

    return $adaquiz;
}

/**
 * Delete all the attempts belonging to an adaptive quiz.
 *
 * @param object $adaquiz The adaptive quiz object.
 */
function adaquiz_delete_all_attempts($adaquiz) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_adaquiz($adaquiz->id));
    $DB->delete_records('adaquiz_attempts', array('quiz' => $adaquiz->id));
    $DB->delete_records('adaquiz_grades', array('quiz' => $adaquiz->id));
}

/**
 * Get the best current grade for a particular user in an adaptive quiz.
 *
 * @param object $adaquiz the adaptive quiz settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this adaptive quiz, or null if this user does
 * not have a grade on this adaptive quiz.
 */
function adaquiz_get_best_grade($adaquiz, $userid) {
    global $DB;
    $grade = $DB->get_field('adaquiz_grades', 'grade',
            array('quiz' => $adaquiz->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}

/**
 * Is this a graded adaptive quiz? If this method returns true, you can assume that
 * $adaquiz->grade and $adaquiz->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $adaquiz a row from the adaptive quiz table.
 * @return bool whether this is a graded adaptive quiz.
 */
function adaquiz_has_grades($adaquiz) {
    return $adaquiz->grade >= 0.000005 && $adaquiz->sumgrades >= 0.000005;
}

/**
 * Does this adaptive quiz allow multiple tries?
 *
 * @return bool
 */
function adaquiz_allows_multiple_tries($adaquiz) {
    $bt = question_engine::get_behaviour_type($adaquiz->preferredbehaviour);
    return $bt->allows_multiple_submitted_responses();
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $adaquiz
 * @return object|null
 */
function adaquiz_user_outline($course, $user, $mod, $adaquiz) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'adaquiz', $adaquiz->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

    // Datesubmitted == time created. dategraded == time modified or time overridden
    // if grade was last modified by the user themselves use date graded. Otherwise use
    // date submitted.
    // TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704.
    if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
        $result->time = $grade->dategraded;
    } else {
        $result->time = $grade->datesubmitted;
    }

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $adaquiz
 * @return bool
 */
function adaquiz_user_complete($course, $user, $mod, $adaquiz) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'adaquiz', $adaquiz->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($attempts = $DB->get_records('adaquiz_attempts',
            array('userid' => $user->id, 'quiz' => $adaquiz->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'adaquiz', $attempt->attempt) . ': ';
            if ($attempt->state != adaquiz_attempt::FINISHED) {
                echo adaquiz_attempt_state_name($attempt->state);
            } else {
                echo adaquiz_format_grade($adaquiz, $attempt->sumgrades) . '/' .
                        adaquiz_format_grade($adaquiz, $adaquiz->sumgrades);
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
        print_string('noattempts', 'adaquiz');
    }

    return true;
}

/**
 * Adaptive quiz periodic clean-up tasks.
 */
function adaquiz_cron() {
    global $CFG;

    require_once($CFG->dirroot . '/mod/adaquiz/cronlib.php');
    mtrace('');

    $timenow = time();
    $overduehander = new mod_adaquiz_overdue_attempt_updater();

    $processto = $timenow - get_config('adaquiz', 'graceperiodmin');

    mtrace('  Looking for adaptive quiz overdue adpative quiz attempts...');

    list($count, $adaquizcount) = $overduehander->update_overdue_attempts($timenow, $processto);

    mtrace('  Considered ' . $count . ' attempts in ' . $adaquizcount . 'adaptive  quizzes.');

    // Run cron for our sub-plugin types.
    cron_execute_plugin_type('adaquiz', 'adaquiz reports');
    cron_execute_plugin_type('adaquizaccess', 'adaquiz access rules');

    return true;
}

/**
 * @param int $adaquizid the adaptive quiz id.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this adaptive quiz. Returns an empty
 *      array if there are none.
 */
function adaquiz_get_user_attempts($adaquizid, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = adaquiz_attempt::FINISHED;
            $params['state2'] = adaquiz_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = adaquiz_attempt::IN_PROGRESS;
            $params['state2'] = adaquiz_attempt::OVERDUE;
            break;
    }

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    $params['adaquizid'] = $adaquizid;
    $params['userid'] = $userid;
    return $DB->get_records_select('adaquiz_attempts',
            'quiz = :adaquizid AND userid = :userid' . $previewclause . $statuscondition,
            $params, 'attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $adaquizid id of the adaptive quiz
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with adaquiz_format_grade for display.
 */
function adaquiz_get_user_grades($adaquiz, $userid = 0) {
    global $CFG, $DB;

    $params = array($adaquiz->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {adaquiz_grades} qg ON u.id = qg.userid
            JOIN {adaquiz_attempts} qa ON qa.quiz = qg.quiz AND qa.userid = u.id

            WHERE qg.quiz = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $adaquiz The adaptive quiz table row, only $adaquiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function adaquiz_format_grade($adaquiz, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'adaquiz');
    }
    return format_float($grade, $adaquiz->decimalpoints);
}

/**
 * Determine the correct number of decimal places required to format a grade.
 *
 * @param object $adaquiz The adaptive quiz table row, only $adaquiz->decimalpoints is used.
 * @return integer
 */
function adaquiz_get_grade_format($adaquiz) {
    if (empty($adaquiz->questiondecimalpoints)) {
        $adaquiz->questiondecimalpoints = -1;
    }

    if ($adaquiz->questiondecimalpoints == -1) {
        return $adaquiz->decimalpoints;
    }

    return $adaquiz->questiondecimalpoints;
}

/**
 * Round a grade to the correct number of decimal places, and format it for display.
 *
 * @param object $adaquiz The adaptive quiz table row, only $adaquiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function adaquiz_format_question_grade($adaquiz, $grade) {
    return format_float($grade, adaquiz_get_grade_format($adaquiz));
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $adaquiz the adaptive quiz settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function adaquiz_update_grades($adaquiz, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($adaquiz->grade == 0) {
        adaquiz_grade_item_update($adaquiz);

    } else if ($grades = adaquiz_get_user_grades($adaquiz, $userid)) {
        adaquiz_grade_item_update($adaquiz, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        adaquiz_grade_item_update($adaquiz, $grade);

    } else {
        adaquiz_grade_item_update($adaquiz);
    }
}

/**
 * Create or update the grade item for given adaptive quiz
 *
 * @category grade
 * @param object $adaquiz object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function adaquiz_grade_item_update($adaquiz, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (array_key_exists('cmidnumber', $adaquiz)) { // May not be always present.
        $params = array('itemname' => $adaquiz->name, 'idnumber' => $adaquiz->cmidnumber);
    } else {
        $params = array('itemname' => $adaquiz->name);
    }

    if ($adaquiz->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $adaquiz->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the adaptive quiz is set to not show grades while theadaptive  quiz is still open,
    //    and is set to show grades after the adpative quiz is closed, then create the
    //    grade_item with a show-after date that is the adaptive quiz close date.
    // 2. If the adaptive quiz is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the adaptive quiz is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_adaquiz_display_options::make_from_adaquiz($adaquiz,
            mod_adaquiz_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_adaquiz_display_options::make_from_adaquiz($adaquiz,
            mod_adaquiz_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($adaquiz->timeclose) {
            $params['hidden'] = $adaquiz->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if (!$params['hidden']) {
        // If the grade item is not hidden by the adaptive quiz logic, then we need to
        // hide it if the adaptive quiz is hidden from students.
        if (property_exists($adaquiz, 'visible')) {
            // Saving the adaptive quiz form, and cm not yet updated in the database.
            $params['hidden'] = !$adaquiz->visible;
        } else {
            $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id);
            $params['hidden'] = !$cm->visible;
        }
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($adaquiz->course, 'mod', 'adaquiz', $adaquiz->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/adaquiz/report.php?q=' . $adaquiz->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/adaquiz', $adaquiz->course, 'mod', 'adaquiz', $adaquiz->id, 0, $grades, $params);
}

/**
 * Delete grade item for given adaptive quiz
 *
 * @category grade
 * @param object $adaquiz object
 * @return object adaptive quiz
 */
function adaquiz_grade_item_delete($adaquiz) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/adaquiz', $adaquiz->course, 'mod', 'adaquiz', $adaquiz->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every adaptive quiz event in the site is checked, else
 * only adaptive quiz events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @return bool
 */
function adaquiz_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (!$adaquizzes = $DB->get_records('adaquiz')) {
            return true;
        }
    } else {
        if (!$adaquizzes = $DB->get_records('adaquiz', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($adaquizzes as $adaquiz) {
        adaquiz_update_events($adaquiz);
    }

    return true;
}

/**
 * Returns all adaptive quiz graded users since a given time for specified adaptive quiz
 */
function adaquiz_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

    $course = get_course($courseid);
    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $adaquiz = $DB->get_record('adaquiz', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['adaquizid'] = $adaquiz->id;

    $ufields = user_picture::fields('u', null, 'useridagain');
    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     {$ufields}
                FROM {adaquiz_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.quiz = :adaquizid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/adaquiz:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                $usersgroups = groups_get_all_groups($course->id,
                        $attempt->userid, $cm->groupingid);
                $usersgroups = array_keys($usersgroups);
                if (!array_intersect($usersgroups, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $options = adaquiz_get_review_options($adaquiz, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'adaquiz';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (adaquiz_has_grades($adaquiz) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = adaquiz_format_grade($adaquiz, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = adaquiz_format_grade($adaquiz, $adaquiz->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = user_picture::unalias($attempt, null, 'useridagain');
        $tmpactivity->user->fullname  = fullname($tmpactivity->user, $viewfullnames);

        $activities[$index++] = $tmpactivity;
    }
}

function adaquiz_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo '<img src="' . $OUTPUT->pix_url('icon', $activity->type) . '" ' .
                'class="icon" alt="' . $modname . '" />';
        echo '<a href="' . $CFG->wwwroot . '/mod/adaquiz/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'adaquiz', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/adaquiz/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the adaptive quiz options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $adaquiz The variables set on the form.
 */
function adaquiz_process_options($adaquiz) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $adaquiz->timemodified = time();

    // Adaptive quiz name.
    if (!empty($adaquiz->name)) {
        $adaquiz->name = trim($adaquiz->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $adaquiz->password = $adaquiz->adaquizpassword;
    unset($adaquiz->adaquizpassword);

    // Adaptive quiz feedback.
    if (isset($adaquiz->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($adaquiz->feedbacktext); $i += 1) {
            if (empty($adaquiz->feedbacktext[$i]['text'])) {
                $adaquiz->feedbacktext[$i]['text'] = '';
            } else {
                $adaquiz->feedbacktext[$i]['text'] = trim($adaquiz->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($adaquiz->feedbackboundaries[$i])) {
            $boundary = trim($adaquiz->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $adaquiz->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'adaquiz', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $adaquiz->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'adaquiz', $i + 1);
            }
            if ($i > 0 && $boundary >= $adaquiz->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'adaquiz', $i + 1);
            }
            $adaquiz->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($adaquiz->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($adaquiz->feedbackboundaries); $i += 1) {
                if (!empty($adaquiz->feedbackboundaries[$i]) &&
                        trim($adaquiz->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'adaquiz', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($adaquiz->feedbacktext); $i += 1) {
            if (!empty($adaquiz->feedbacktext[$i]['text']) &&
                    trim($adaquiz->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'adaquiz', $i + 1);
            }
        }
        // Needs to be bigger than $adaquiz->grade because of '<' test in adaquiz_feedback_for_grade().
        $adaquiz->feedbackboundaries[-1] = $adaquiz->grade + 1;
        $adaquiz->feedbackboundaries[$numboundaries] = 0;
        $adaquiz->feedbackboundarycount = $numboundaries;
    } else {
        $adaquiz->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $adaquiz->reviewattempt = adaquiz_review_option_form_to_db($adaquiz, 'attempt');
    $adaquiz->reviewcorrectness = adaquiz_review_option_form_to_db($adaquiz, 'correctness');
    $adaquiz->reviewmarks = adaquiz_review_option_form_to_db($adaquiz, 'marks');
    $adaquiz->reviewspecificfeedback = adaquiz_review_option_form_to_db($adaquiz, 'specificfeedback');
    $adaquiz->reviewgeneralfeedback = adaquiz_review_option_form_to_db($adaquiz, 'generalfeedback');
    $adaquiz->reviewrightanswer = adaquiz_review_option_form_to_db($adaquiz, 'rightanswer');
    $adaquiz->reviewoverallfeedback = adaquiz_review_option_form_to_db($adaquiz, 'overallfeedback');
    $adaquiz->reviewattempt |= mod_adaquiz_display_options::DURING;
    $adaquiz->reviewoverallfeedback &= ~mod_adaquiz_display_options::DURING;
}

/**
 * Helper function for {@link adaquiz_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function adaquiz_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_adaquiz_display_options::DURING,
        'immediately' => mod_adaquiz_display_options::IMMEDIATELY_AFTER,
        'open' => mod_adaquiz_display_options::LATER_WHILE_OPEN,
        'closed' => mod_adaquiz_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (isset($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of adaquiz_add_instance
 * and adaquiz_update_instance, to do the common processing.
 *
 * @param object $adaquiz the adaptive quiz object.
 */
function adaquiz_after_add_or_update($adaquiz) {
    global $DB;
    $cmid = $adaquiz->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $adaquiz->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('adaquiz_feedback', array('adaquizid' => $adaquiz->id));

    for ($i = 0; $i <= $adaquiz->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->adaquizid = $adaquiz->id;
        $feedback->feedbacktext = $adaquiz->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $adaquiz->feedbacktext[$i]['format'];
        $feedback->mingrade = $adaquiz->feedbackboundaries[$i];
        $feedback->maxgrade = $adaquiz->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('adaquiz_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$adaquiz->feedbacktext[$i]['itemid'],
                $context->id, 'mod_adaquiz', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $adaquiz->feedbacktext[$i]['text']);
        $DB->set_field('adaquiz_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }

    // Store any settings belonging to the access rules.
    adaquiz_access_manager::save_settings($adaquiz);

    // Update the events relating to this adaptive quiz.
    adaquiz_update_events($adaquiz);

    // Update related grade item.
    adaquiz_grade_item_update($adaquiz);
}

/**
 * This function updates the events associated to the adaptive quiz.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses ADAQUIZ_MAX_EVENT_LENGTH
 * @param object $adaquiz the adaptive quiz object.
 * @param object optional $override limit to a specific override
 */
function adaquiz_update_events($adaquiz, $override = null) {
    global $DB;

    // Load the old events relating to this adaptive quiz.
    $conds = array('modulename'=>'adaquiz',
                   'instance'=>$adaquiz->id);
    if (!empty($override)) {
        // Only load events for this override.
        $conds['groupid'] = isset($override->groupid)?  $override->groupid : 0;
        $conds['userid'] = isset($override->userid)?  $override->userid : 0;
    }
    $oldevents = $DB->get_records('event', $conds);

    // Now make a todo list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the adaptive quiz, so we
        // need to add all the overrides.
        $overrides = $DB->get_records('adaquiz_overrides', array('quiz' => $adaquiz->id));
        // As well as the original adaptive quiz (empty override).
        $overrides[] = new stdClass();
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $adaquiz->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $adaquiz->timeclose;

        // Only add open/close events for an override if they differ from the adaptive quiz default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($adaquiz->coursemodule)) {
            $cmid = $adaquiz->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('adaquiz', $adaquiz->id, $adaquiz->course)->id;
        }

        $event = new stdClass();
        $event->description = format_module_intro('adaquiz', $adaquiz, $cmid);
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $adaquiz->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'adaquiz';
        $event->instance    = $adaquiz->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->visible     = instance_is_visible('adaquiz', $adaquiz);
        $event->eventtype   = 'open';

        // Determine the event name.
        if ($groupid) {
            $params = new stdClass();
            $params->adaquiz = $adaquiz->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'adaquiz', $params);
        } else if ($userid) {
            $params = new stdClass();
            $params->adaquiz = $adaquiz->name;
            $eventname = get_string('overrideusereventname', 'adaquiz', $params);
        } else {
            $eventname = $adaquiz->name;
        }
        if ($addopen or $addclose) {
            if ($timeclose and $timeopen and $event->timeduration <= ADAQUIZ_MAX_EVENT_LENGTH) {
                // Single event for the whole adaptive quiz.
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = $eventname;
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event);
            } else {
                // Separate start and end events.
                $event->timeduration  = 0;
                if ($timeopen && $addopen) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name = $eventname.' ('.get_string('quizopens', 'adaquiz').')';
                    // The method calendar_event::create will reuse a db record if the id field is set.
                    calendar_event::create($event);
                }
                if ($timeclose && $addclose) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name      = $eventname.' ('.get_string('quizcloses', 'adaquiz').')';
                    $event->timestart = $timeclose;
                    $event->eventtype = 'close';
                    calendar_event::create($event);
                }
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function adaquiz_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function adaquiz_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function adaquiz_questions_in_use($questionids) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    list($test, $params) = $DB->get_in_or_equal($questionids);
    return $DB->record_exists_select('adaquiz_slots',
            'questionid ' . $test, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{adaquiz_attempts} quiza',
            'quiza.uniqueid', 'quiza.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the adaptive quiz.
 *
 * @param $mform the course reset form that is being built.
 */
function adaquiz_reset_course_form_definition($mform) {
    $mform->addElement('header', 'adaquizheader', get_string('modulenameplural', 'adaquiz'));
    $mform->addElement('advcheckbox', 'reset_adaquiz_attempts',
            get_string('removeallquizattempts', 'adaquiz'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function adaquiz_reset_course_form_defaults($course) {
    return array('reset_adaquiz_attempts' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function adaquiz_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $adaquizzes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {adaquiz} q ON cm.instance = q.id
            WHERE m.name = 'quiz' AND cm.course = ?", array($courseid));

    foreach ($adaquizzes as $adaquiz) {
        adaquiz_grade_item_update($adaquiz, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * adaptive quiz attempts for course $data->courseid, if $data->reset_adaquiz_attempts is
 * set and true.
 *
 * Also, move the adaptive fquiz open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function adaquiz_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'adaquiz');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_quiz_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{adaquiz_attempts} quiza JOIN {adaquiz} quiz ON quiza.quiz = quiz.id',
                'quiza.uniqueid', 'quiz.course = :quizcourseid',
                array('quizcourseid' => $data->courseid)));

        $DB->delete_records_select('adaquiz_attempts',
                'quiz IN (SELECT id FROM {adaquiz} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'adaquiz'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('adaquiz_grades',
                'quiz IN (SELECT id FROM {adaquiz} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            adaquiz_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'adaquiz'),
            'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {adaquiz_overrides}
                         SET timeopen = timeopen + ?
                       WHERE quiz IN (SELECT id FROM {adaquiz} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {adaquiz_overrides}
                         SET timeclose = timeclose + ?
                       WHERE quiz IN (SELECT id FROM {adaquiz} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        shift_course_mod_dates('adaquiz', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'adaquiz'),
            'error' => false);
    }

    return $status;
}

/**
 * Prints adaptive quiz summaries on MyMoodle Page
 * @param arry $courses
 * @param array $htmlarray
 */
function adaquiz_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;
    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$adaquizzes = get_all_instances_in_courses('adaquiz', $courses)) {
        return;
    }

    // Fetch some language strings outside the main loop.
    $strquiz = get_string('modulename', 'adaquiz');
    $strnoattempts = get_string('noattempts', 'adaquiz');

    // We want to list adaptive quizzes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($adaquizzes as $adaquiz) {
        if ($adaquiz->timeclose >= $now && $adaquiz->timeopen < $now) {
            // Give a link to the adaptive quiz, and the deadline.
            $str = '<div class="quiz overview">' .
                    '<div class="name">' . $strquiz . ': <a ' .
                    ($adaquiz->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/adaquiz/view.php?id=' .
                    $adaquiz->coursemodule . '">' .
                    $adaquiz->name . '</a></div>';
            $str .= '<div class="info">' . get_string('quizcloseson', 'adaquiz',
                    userdate($adaquiz->timeclose)) . '</div>';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($adaquiz->coursemodule);
            if (has_capability('mod/adaquiz:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $adaquiz objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' .
                        adaquiz_num_attempt_summary($adaquiz, $adaquiz, true) . '</div>';
            } else if (has_any_capability(array('mod/adaquiz:reviewmyattempts', 'mod/adaquiz:attempt'),
                    $context)) { // Student
                // For student-like people, tell them how many attempts they have made.
                if (isset($USER->id) &&
                        ($attempts = adaquiz_get_user_attempts($adaquiz->id, $USER->id))) {
                    $numattempts = count($attempts);
                    $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'adaquiz', $numattempts) . '</div>';
                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }
            } else {
                // For ayone else, there is no point listing this adaptive quiz, so stop processing.
                continue;
            }

            // Add the output for this adaptive quiz to the rest.
            $str .= '</div>';
            if (empty($htmlarray[$adaquiz->course]['adaquiz'])) {
                $htmlarray[$adaquiz->course]['adaquiz'] = $str;
            } else {
                $htmlarray[$adaquiz->course]['adaquiz'] .= $str;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular adaptive quiz,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $adaquiz the adaptive quiz object. Only $adaquiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function adaquiz_num_attempt_summary($adaquiz, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('adaquiz_attempts', array('quiz'=> $adaquiz->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{adaquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quiz = ? AND preview = 0 AND groupid = ?',
                        array($adaquiz->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'adaquiz', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{adaquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quiz = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($adaquiz->id), $params));
                return get_string('attemptsnumyourgroups', 'adaquiz', $a);
            }
        }
        return get_string('attemptsnum', 'adaquiz', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link adaquiz_num_attempt_summary()} but wrapped in a link
 * to the adaptive quiz reports.
 *
 * @param object $quiz the adaptive quiz object. Only $adaquiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the adaptive quiz context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function adaquiz_attempt_summary_link_to_reports($adaquiz, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = adaquiz_num_attempt_summary($adaquiz, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/adaquiz/report/reportlib.php');
    $url = new moodle_url('/mod/adaquiz/report.php', array(
            'id' => $cm->id, 'mode' => adaquiz_report_default_report($context)));
    return html_writer::link($url, $summary);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if adaptive quiz supports feature
 */
function adaquiz_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_COMPLETION_HAS_RULES:      return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;
        case FEATURE_USES_QUESTIONS:            return true;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function adaquiz_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    $caps = question_get_all_capabilities();
    $caps[] = 'moodle/site:accessallgroups';
    return $caps;
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $adaquiznode
 * @return void
 */
function adaquiz_extend_settings_navigation($settings, $adaquiznode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $adaquiznode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_capability('mod/adaquiz:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/adaquiz/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'adaquiz'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_groupoverrides');
        $adaquiznode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'adaquiz'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_useroverrides');
        $adaquiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/adaquiz:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editquiz', 'adaquiz'),
                new moodle_url('/mod/adaquiz/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_edit',
                new pix_icon('t/edit', ''));
        $adaquiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/adaquiz:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/adaquiz/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'adaquiz'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_preview',
                new pix_icon('i/preview', ''));
        $adaquiznode->add_node($node, $beforekey);
    }

    if (has_any_capability(array('mod/adaquiz:viewreports', 'mod/adaquiz:grade'), $PAGE->cm->context)) {
        require_once($CFG->dirroot . '/mod/adaquiz/report/reportlib.php');
        $reportlist = adaquiz_report_list($PAGE->cm->context);

        $url = new moodle_url('/mod/adaquiz/report.php',
                array('id' => $PAGE->cm->id, 'mode' => reset($reportlist)));
        $reportnode = $adaquiznode->add_node(navigation_node::create(get_string('results', 'adaquiz'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', '')), $beforekey);

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/adaquiz/report.php',
                    array('id' => $PAGE->cm->id, 'mode' => $report));
            $reportnode->add_node(navigation_node::create(get_string($report, 'adaquiz_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'adaquiz_report_' . $report, new pix_icon('i/item', '')));
        }
    }

    question_extend_settings_navigation($adaquiznode, $PAGE->cm->context)->trim_if_empty();
}

/**
 * Serves the adaptive quiz files.
 *
 * @package  mod_adaquiz
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function adaquiz_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$adaquiz = $DB->get_record('adaquiz', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('adaquiz_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_adaquiz/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is an adaptive quiz attempt.
 *
 * @package  mod_adaquiz
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this adaptive quiz attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function adaquiz_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

    $attemptobj = adaquiz_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/adaquiz:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function adaquiz_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-adaquiz-*'       => get_string('page-mod-adaquiz-x', 'adaquiz'),
        'mod-adaquiz-view'    => get_string('page-mod-adaquiz-view', 'adaquiz'),
        'mod-adaquiz-attempt' => get_string('page-mod-adaquiz-attempt', 'adaquiz'),
        'mod-adaquiz-summary' => get_string('page-mod-adaquiz-summary', 'adaquiz'),
        'mod-adaquiz-review'  => get_string('page-mod-adaquiz-review', 'adaquiz'),
        'mod-adaquiz-edit'    => get_string('page-mod-adaquiz-edit', 'adaquiz'),
        'mod-adaquiz-report'  => get_string('page-mod-adaquiz-report', 'adaquiz'),
    );
    return $module_pagetype;
}

/**
 * @return the options for adaptive quiz navigation.
 */
function adaquiz_get_navigation_options() {
    return array(
        ADAQUIZ_NAVMETHOD_FREE => get_string('navmethod_free', 'adaquiz'),
        ADAQUIZ_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'adaquiz')
    );
}

/**
 * Obtains the automatic completion state for this adaptive quiz on any conditions
 * in adaptive quiz settings, such as if all attempts are used or a certain grade is achieved.
 *
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function adaquiz_get_completion_state($course, $cm, $userid, $type) {
    global $DB;
    global $CFG;

    $adaquiz = $DB->get_record('adaquiz', array('id' => $cm->instance), '*', MUST_EXIST);
    if (!$adaquiz->completionattemptsexhausted && !$adaquiz->completionpass) {
        return $type;
    }

    // Check if the user has used up all attempts.
    if ($adaquiz->completionattemptsexhausted) {
        $attempts = adaquiz_get_user_attempts($adaquiz->id, $userid, 'finished', true);
        if ($attempts) {
            $lastfinishedattempt = end($attempts);
            $context = context_module::instance($cm->id);
            $adaquizobj = adaquiz::create($adaquiz->id, $userid);
            $accessmanager = new adaquiz_access_manager($adaquizobj, time(),
                    has_capability('mod/adaquiz:ignoretimelimits', $context, $userid, false));
            if ($accessmanager->is_finished(count($attempts), $lastfinishedattempt)) {
                return true;
            }
        }
    }

    // Check for passing grade.
    if ($adaquiz->completionpass) {
        require_once($CFG->libdir . '/gradelib.php');
        $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
                'itemmodule' => 'adaquiz', 'iteminstance' => $cm->instance, 'outcomeid' => null));
        if ($item) {
            $grades = grade_grade::fetch_users_grades($item, array($userid), false);
            if (!empty($grades[$userid])) {
                return $grades[$userid]->is_passed($item);
            }
        }
    }
    return false;
}
