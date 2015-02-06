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
 * @package    mod
 * @subpackage adaquiz
 * @copyright  2014 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This file contains the functions that will be called from Moodle's core.
 * These functions will delegate the work to apropiate classes.
 * **/

require_once($CFG->dirroot.'/mod/adaquiz/lib/Adaquiz.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/Attempt.php');


/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if quiz supports feature
 */
function adaquiz_supports($feature) {
    switch($feature) {
        //case FEATURE_GROUPS:                    return true;
        //case FEATURE_GROUPINGS:                 return true;
        //case FEATURE_GROUPMEMBERSONLY:          return true;
        //case FEATURE_MOD_INTRO:                 return true;
        //case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        //case FEATURE_GRADE_HAS_GRADE:           return true;
        //case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        //case FEATURE_SHOW_DESCRIPTION:          return true;
        //case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;

        default: return null;
    }
}

/**
 * will be called during the installation of the module
 */
function adaquiz_install(){
  return true;
}
function adaquiz_uninstall(){
  return true;
}

/**
 * @param  object $values the data that came from the form.
 * @return mixed  the id of the new instance on success,
 *                false or a string error message on failure.
 */
function adaquiz_add_instance($values){
  $adaquiz = new Adaquiz($values);
  return $adaquiz->save();
}
function adaquiz_update_instance($values){
  $values->id = $values->instance;
  $adaquiz = new Adaquiz($values);
  if(!$adaquiz) return false;
  
  $adaquiz->name = $values->name;
  $adaquiz->intro = $values->intro;
  $adaquiz->options['preferredbehaviour'] = $values->preferredbehaviour; 
  
  $adaquiz->options['review']['adaquiz']['correctanswer'] = $values->afterquizcorrectanswer;
  $adaquiz->options['review']['adaquiz']['feedback'] = $values->afterquizfeedback;
  $adaquiz->options['review']['adaquiz']['score'] = $values->afterquizscore;
  $adaquiz->options['review']['adaquiz']['useranswer'] = $values->afterquizuseranswer;
  $adaquiz->options['review']['question']['correctanswer'] = $values->afterquestioncorrectanswer;
  $adaquiz->options['review']['question']['feedback'] = $values->afterquestionfeedback;
  $adaquiz->options['review']['question']['score'] = $values->afterquestionscore;
  $adaquiz->options['review']['question']['useranswer'] = $values->afterquestionuseranswer;   
  
  return $adaquiz->save();
}
function adaquiz_delete_instance($id){
  $adaquiz = new Adaquiz(intval($id));
  if(!$adaquiz) return false;
  return $adaquiz->delete();
}
/**
 * GRADEBOOK
 * **/

/**
 * Update grades in central gradebook
 * @param object $ada null means all adaquizzes
 * @param int $userid specific user only, 0 mean all
 */
function adaquiz_update_grades($ada=null, $userid=0, $nullifnone=true){
  if($ada!=null){
    $adaquiz = new Adaquiz($ada);
    $adaquiz->updateGrades($userid, $nullifnone);
  }else{
    Adaquiz::updateAllGrades();
  }
}

function adaquiz_grade_item_update($ada, $grades=NULL){
  $adaquiz = new Adaquiz($ada);
  $adaquiz->gradeItemUpdate($grades);
}

function adaquiz_get_participants($adaquizid){
  $adaquiz = new Adaquiz($adaquizid);
  return $adaquiz->getParticipants();
}


/**
 * Returns the same as {@link quiz_num_attempt_summary()} but wrapped in a link
 * to the quiz reports.
 *
 * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the quiz context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function adaquiz_attempt_summary_link_to_reports($quiz, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = adaquiz_num_attempt_summary($quiz, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/adaquiz/report/reportlib.php');
    $url = new moodle_url('/mod/adaquiz/report.php', array(
            'id' => $cm->id, 'mode' => quiz_report_default_report($context)));
    return html_writer::link($url, $summary);
}


/**
 * given an instance, return a summary of a user's contribution
 * **/
function adaquiz_user_outline($course, $user, $mod, $adaquiz){
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'adaquiz', $adaquiz->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass;
    $result->info = get_string('grade') . ': ' . $grade->str_long_grade;
    $result->time = $grade->dategraded;
    return $result;
}
/**
 * given an instance, print details of a user's contribution
 * **/
function adaquiz_user_complete($course, $user, $mod, $adaquiz){
  $info = adaquiz_user_outline($course, $user, $mod, $adaquiz);
  echo '<p>'.$info->info.' - '.$info->time.'</p>';
  return true;
}
function adaquiz_print_recent_activity($course, $isteacher, $timestart){}

function adaquiz_cron(){
  return true;
}

/*
function adaquiz_scale_used($adaquizid, $scaleid){}
function adaquiz_scale_used_anywhere($scaleid){}
 */


/**
 * Used by the participation report (course/report/participation/index.php) to classify actions in the logs table.
 * **/
 //NOT ACTUALLY USED BECAUSE NO LOGGING
function adaquiz_get_view_actions(){
  return array('view','view all','report');
}
/**
 * Used by the participation report (course/report/participation/index.php) to classify actions in the logs table.
 * **/
 //NOT ACTUALLY USED BECAUSE NO LOGGING
function adaquiz_get_post_actions(){
  return array('attempt','editquestions','review','submit');
}
/**
 * code to pre-process the form data from module settings
 * **/
function adaquiz_process_options(){}
/**
 * used to implement Reset course feature.
 * **/
function adaquiz_reset_course_form_definition(&$mform){
  $mform->addElement('header', 'adaquizheader', get_string('modulenameplural', 'adaquiz'));
  $mform->addElement('checkbox', 'reset_adaquiz_all', get_string('resetadaquizall','adaquiz'));
}
/**
 * used to implement Reset course feature.
 * **/
function adaquiz_reset_userdata($data){
  if (!empty($data->reset_adaquiz_all)) {
    $adaquizzes = get_records(Adaquiz::TABLE, 'course', $data->courseid);
    foreach($adaquizzes as $ada){
      $adaquiz = new Adaquiz($ada);
      $attempts = $adaquiz->getAllAttempts();
      foreach($attempts as $attempt){
        $attempt->delete();
      }
    }
  }
  return true;
}

/**
 * @param int $quizid the quiz id.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this quiz. Returns an empty
 *      array if there are none.
 */
function adaquiz_get_user_attempts($quizid, $userid, $status = 'finished', $includepreviews = false) {
    global $DB;

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = Attempt::STATE_FINISHED;
            $params['state2'] = Attempt::STATE_REVIEWING;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1)';
            $params['state1'] = Attempt::STATE_ANSWERING;
            break;
    }

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    $params['adaquizid'] = $quizid;
    $params['userid'] = $userid;
    
    return $DB->get_records_select('adaquiz_attempt', 'adaquiz = :adaquizid AND userid = :userid' 
            . $previewclause . $statuscondition, $params, 'timecreated ASC');
}

/**
 * Updates a quiz object with override information for a user.
 *
 * Algorithm:  For each quiz setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the quiz setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   quiz->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $quiz The quiz object.
 * @param int $userid The userid.
 * @return object $quiz The updated quiz object.
 */
function adaquiz_update_effective_access($quiz, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('quiz_overrides', array('quiz' => $quiz->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($quiz->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {quiz_overrides}
                WHERE groupid $extra AND quiz = ?";
        $params[] = $quiz->id;
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
            $override->timeclose = max($closes);
        }
        if (is_null($override->timelimit) && count($limits)) {
            $override->timelimit = max($limits);
        }
        if (is_null($override->attempts) && count($attempts)) {
            $override->attempts = max($attempts);
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with quiz defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $quiz->{$key} = $override->{$key};
        }
    }

    return $quiz;
}


/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $quiznode
 */
function adaquiz_extend_settings_navigation($settings, $quiznode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $quiznode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    /*if (has_capability('mod/quiz:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/adaquiz/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'quiz'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_groupoverrides');
        $quiznode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'quiz'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_useroverrides');
        $quiznode->add_node($node, $beforekey);
    }*/

    if (has_capability('mod/adaquiz:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editquiz', 'adaquiz'),
                new moodle_url('/mod/adaquiz/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_edit',
                new pix_icon('t/edit', ''));
        $quiznode->add_node($node, $beforekey);
    }

    if (has_capability('mod/adaquiz:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/adaquiz/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'adaquiz'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_adaquiz_preview',
                new pix_icon('t/preview', ''));
        $quiznode->add_node($node, $beforekey);
    }

    question_extend_settings_navigation($quiznode, $PAGE->cm->context)->trim_if_empty();
}


/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quiz The quiz table row, only $quiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function adaquiz_format_question_grade($quiz, $grade) {
    if (empty($quiz->questiondecimalpoints)) {
        $quiz->questiondecimalpoints = -1;
    }
    /*if ($quiz->questiondecimalpoints == -1) {
        return format_float($grade, $quiz->decimalpoints);
    } else {
        return format_float($grade, $quiz->questiondecimalpoints);
    }*/
    return format_float($grade, 2);
}

/**
 * Is this a graded quiz? If this method returns true, you can assume that
 * $quiz->grade and $quiz->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $quiz a row from the quiz table.
 * @return bool whether this is a graded quiz.
 */
function adaquiz_has_grades($quiz) {
    //return $quiz->grade >= 0.000005 && $quiz->sumgrades >= 0.000005;
    return true;
}


/**
 * This fucntion extends the global navigation for the site.
 * It is important to note that you should not rely on PAGE objects within this
 * body of code as there is no guarantee that during an AJAX request they are
 * available
 *
 * @param navigation_node $quiznode The adaquiz node within the global navigation
 * @param object $course The course object returned from the DB
 * @param object $module The module object returned from the DB
 * @param object $cm The course module instance returned from the DB
 */
function adaquiz_extend_navigation($quiznode, $course, $module, $cm) {
    global $CFG;

    $context = context_module::instance($cm->id);

    if (has_capability('mod/adaquiz:view', $context)) {
        $url = new moodle_url('/mod/adaquiz/view.php', array('id'=>$cm->id));
        $quiznode->add(get_string('info', 'adaquiz'), $url, navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/info', ''));
    }

    if (has_any_capability(array('mod/adaquiz:viewreports', 'mod/adaquiz:grade'), $context)) {
        require_once($CFG->dirroot . '/mod/adaquiz/report/reportlib.php');
        //$reportlist = quiz_report_list($context);
        $reportlist = array('overview');

        $url = new moodle_url('/mod/adaquiz/report.php',
                array('id' => $cm->id, 'mode' => reset($reportlist)));
        $reportnode = $quiznode->add(get_string('results', 'adaquiz'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', ''));

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/adaquiz/report.php',
                    array('id' => $cm->id, 'mode' => $report));
            $reportnode->add(get_string($report, 'quiz_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'quiz_report_' . $report, new pix_icon('i/item', ''));
        }
    }
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quiz The quiz table row, only $quiz->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function adaquiz_format_grade($quiz, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'adaquiz');
    }
    return format_float($grade, $quiz->decimalpoints);
}


/**
 * Return a textual summary of the number of attempts that have been made at a particular quiz,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function adaquiz_num_attempt_summary($quiz, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('adaquiz_attempt', array('adaquiz'=> $quiz->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{adaquiz_attempt} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE adaquiz = ? AND preview = 0 AND groupid = ?',
                        array($quiz->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'adaquiz', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{adaquiz_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE adaquiz = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($quiz->id), $params));
                return get_string('attemptsnumyourgroups', 'adaquiz', $a);
            }
        }
        return get_string('attemptsnum', 'adaquiz', $numattempts);
    }
    return '';
}
