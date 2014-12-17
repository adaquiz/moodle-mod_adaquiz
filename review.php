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

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaquiz/report/reportlib.php');

$attemptid = required_param('attempt', PARAM_INT);
$page      = optional_param('page', 0, PARAM_INT);
$showall   = optional_param('showall', 0, PARAM_BOOL);

$url = new moodle_url('/mod/adaquiz/review.php', array('attempt'=>$attemptid));
if ($page !== 0) {
    $url->param('page', $page);
}
if ($showall !== 0) {
    $url->param('showall', $showall);
}
$PAGE->set_url($url);

$attemptobj = adaquiz_attempt::create($attemptid);
$page = $attemptobj->force_page_number_into_range($page);

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());
$attemptobj->check_review_capability();

$options = $attemptobj->get_display_options(true);

// Check permissions.
if ($attemptobj->is_own_attempt()) {
    if (!$attemptobj->is_finished()) {
        redirect($attemptobj->attempt_url(null, $page));

    }
} else if (!$attemptobj->is_review_allowed()) {
    throw new moodle_quiz_exception($attemptobj->get_quizobj(), 'noreviewattempt');
}

// Load the questions and states needed by this page.
if ($showall) {
    $questionids = $attemptobj->get_slots();
} else {
    $questionids = $attemptobj->get_slots($page);
}

// Save the flag states, if they are being changed.
if ($options->flags == question_display_options::EDITABLE && optional_param('savingflags', false,
        PARAM_BOOL)) {
    require_sesskey();
    $attemptobj->save_question_flags();
    redirect($attemptobj->review_url(null, $page, $showall));
}

// Work out appropriate title and whether blocks should be shown.
if ($attemptobj->is_preview_user() && $attemptobj->is_own_attempt()) {
    $strreviewtitle = get_string('reviewofpreview', 'adaquiz');
    navigation_node::override_active_url($attemptobj->start_attempt_url());

} else {
    $strreviewtitle = get_string('reviewofattempt', 'adaquiz', '');
    if (empty($attemptobj->get_quiz()->showblocks) && !$attemptobj->is_preview_user()) {
        $PAGE->blocks->show_only_fake_blocks();
    }
}

// Set up the page header.
$headtags = $attemptobj->get_html_head_contributions($page, $showall);
$PAGE->set_title(format_string($attemptobj->get_quiz_name()));
$PAGE->set_heading($attemptobj->get_course()->fullname);

// Summary table start. ============================================================================
// Work out some time-related things.
$attempt = $attemptobj->get_attempt();
$attempt->decimalpoints = 2;
$quiz = $attemptobj->get_quiz();
$overtime = 0;

if ((int)$attempt->state == Attempt::STATE_FINISHED) {
    if ($timetaken = ($attempt->timemodified - $attempt->timecreated)) {
        $timetaken = format_time($timetaken);
    }
} else {
    $timetaken = get_string('unfinished', 'adaquiz');
}

// Prepare summary informat about the whole attempt.
$summarydata = array();

// Timing information.
$summarydata['startedon'] = array(
    'title'   => get_string('startedon', 'adaquiz'),
    'content' => userdate($attempt->timecreated),
);

$summarydata['state'] = array(
    'title'   => get_string('attemptstate', 'adaquiz'),
    'content' => adaquiz_attempt::state_name($attempt->state),
);

if ((int)$attempt->state == Attempt::STATE_FINISHED) {
    $summarydata['completedon'] = array(
        'title'   => get_string('completedon', 'adaquiz'),
        'content' => userdate($attempt->timemodified),
    );
    $summarydata['timetaken'] = array(
        'title'   => get_string('timetaken', 'adaquiz'),
        'content' => $timetaken,
    );
}

//To know the real questions the user attempted
$route = adaquiz_get_full_route($attemptobj->get_attemptid());
$route[] = -1;

//Recalculate the quiz->sumgrades
$quiz->sumgrades = adaquiz_get_real_sumgrades($attempt->id, $quiz->id, $route);

// Show marks (if the user is allowed to see marks at the moment).
$grade = adaquiz_rescale_grade($attempt->sumgrades, $quiz, false);
if ($options->marks >= question_display_options::MARK_AND_MAX && adaquiz_has_grades($quiz)) {

    if ($attempt->state != Attempt::STATE_FINISHED) {
        $summarydata['grade'] = array(
            'title'   => get_string('grade', 'adaquiz'),
            'content' => get_string('attemptstillinprogress', 'adaquiz'),
        );

    } else if (is_null($grade)) {
        $summarydata['grade'] = array(
            'title'   => get_string('grade', 'adaquiz'),
            'content' => adaquiz_format_grade($quiz, $grade),
        );

    } else {
        // Show raw marks only if they are different from the grade (like on the view page).
        if ($quiz->grade != $quiz->sumgrades) {
            $a = new stdClass();
            $a->grade = adaquiz_format_grade($quiz, $attempt->sumgrades);
            $a->maxgrade = adaquiz_format_grade($quiz, $quiz->sumgrades);
            $summarydata['marks'] = array(
                'title'   => get_string('marks', 'adaquiz'),
                'content' => get_string('outofshort', 'adaquiz', $a),
            );
        }

        // Now the scaled grade.
        $a = new stdClass();
        $a->grade = html_writer::tag('b', adaquiz_format_grade($quiz, $grade));
        $a->maxgrade = adaquiz_format_grade($quiz, $quiz->grade);
        if ($quiz->grade != 100) {
            $a->percent = html_writer::tag('b', format_float(
                    $attempt->sumgrades * 100 / $quiz->sumgrades, 0));
            $formattedgrade = get_string('outofpercent', 'adaquiz', $a);
        } else {
            $formattedgrade = get_string('outof', 'adaquiz', $a);
        }
        $summarydata['grade'] = array(
            'title'   => get_string('grade', 'adaquiz'),
            'content' => $formattedgrade,
        );
    }
}

// Feedback if there is any, and the user is allowed to see it now.
$feedback = $attemptobj->get_overall_feedback($grade);
if ($options->overallfeedback && $feedback) {
    $summarydata['feedback'] = array(
        'title'   => get_string('feedback', 'adaquiz'),
        'content' => $feedback,
    );
}

// Summary table end. ==============================================================================
if ($showall) {
    $slots = $attemptobj->get_slots();
    $lastpage = true;
} else {
    $slots = $attemptobj->get_slots($page);

    $key = array_search($slots[0], $route) + 1;

    if ($route[$key] == -1){
        $lastpage = true;
    }else{
        $lastpage = false;
    }

}

$output = $PAGE->get_renderer('mod_adaquiz');

// Arrange for the navigation to be displayed.
$navbc = $attemptobj->get_navigation_panel($output, 'quiz_review_nav_panel', $page, $showall);
$regions = $PAGE->blocks->get_regions();
$PAGE->blocks->add_fake_block($navbc, reset($regions));

echo $output->review_page($attemptobj, $slots, $page, $showall, $lastpage, $options, $summarydata, $route);
