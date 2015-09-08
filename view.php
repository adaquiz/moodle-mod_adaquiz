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
 * This page is the entry page into the adaptive quiz UI. Displays information about the
 * adaptive quiz to students and teachers, and lets students see their previous attempts.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/adaquiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/format/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // Adaptive quiz ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('adaquiz', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
} else {
    if (!$adaquiz = $DB->get_record('adaquiz', array('id' => $q))) {
        print_error('invalidquizid', 'adaquiz');
    }
    if (!$course = $DB->get_record('course', array('id' => $adaquiz->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("adaquiz", $adaquiz->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/adaquiz:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/adaquiz:attempt', $context);
$canreviewmine = has_capability('mod/adaquiz:reviewmyattempts', $context);
$canpreview = has_capability('mod/adaquiz:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$adaquizobj = adaquiz::create($cm->instance, $USER->id);
$accessmanager = new adaquiz_access_manager($adaquizobj, $timenow,
        has_capability('mod/adaquiz:ignoretimelimits', $context, null, false));
$adaquiz = $adaquizobj->get_adaquiz();


// Log this request.
$params = array(
    'objectid' => $adaquiz->id,
    'context' => $context
);
$event = \mod_adaquiz\event\course_module_viewed::create($params);
$event->add_record_snapshot('adaquiz', $adaquiz);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/adaquiz/view.php', array('id' => $cm->id));

// Create view object which collects all the information the renderer will need.
$viewobj = new mod_adaquiz_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine;

// Get this user's attempts.
$attempts = adaquiz_get_user_attempts($adaquiz->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
if ($unfinishedattempt = adaquiz_get_user_attempt_unfinished($adaquiz->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $adaquizobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == adaquiz_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == adaquiz_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = array();
foreach ($attempts as $attempt) {
    $viewobj->attemptobjs[] = new adaquiz_attempt($attempt, $adaquiz, $cm, $course, false);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
if (!$canpreview) {
    $mygrade = adaquiz_get_best_grade($adaquiz, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the adaptive quiz don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = adaquiz_rescale_grade($lastfinishedattempt->sumgrades, $adaquiz, false);
} else {
    $mygrade = null;
}

$mygradeoverridden = false;
$gradebookfeedback = '';

$grading_info = grade_get_grades($course->id, 'mod', 'adaquiz', $adaquiz->id, $USER->id);
if (!empty($grading_info->items)) {
    $item = $grading_info->items[0];
    if (isset($item->grades[$USER->id])) {
        $grade = $item->grades[$USER->id];

        if ($grade->overridden) {
            $mygrade = $grade->grade + 0; // Convert to number.
            $mygradeoverridden = true;
        }
        if (!empty($grade->str_feedback)) {
            $gradebookfeedback = $grade->str_feedback;
        }
    }
}

$title = $course->shortname . ': ' . format_string($adaquiz->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_adaquiz');

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = adaquiz_get_combined_reviewoptions($adaquiz, $attempts, $context);

    $viewobj->attemptcolumn  = $adaquiz->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            adaquiz_has_grades($adaquiz);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($adaquiz->grade != $adaquiz->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = adaquiz_has_feedback($adaquiz) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/adaquiz:manage', $context);
$viewobj->editurl = new moodle_url('/mod/adaquiz/edit.php', array('cmid' => $cm->id));
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->startattempturl = $adaquizobj->start_attempt_url();
$viewobj->startattemptwarning = $adaquizobj->confirm_start_attempt_message($unfinished);
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this adaptive quiz.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($adaquiz->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'adaquiz',
            adaquiz_get_grading_option_name($adaquiz->grademethod));
}

// Determine wheter a start attempt button should be displayed.
$viewobj->adaquizhasquestions = $adaquizobj->has_questions();
$viewobj->preventmessages = array();
if (!$viewobj->adaquizhasquestions) {
    $viewobj->buttontext = '';

} else {
    if ($unfinished) {
        if ($canattempt) {
            $viewobj->buttontext = get_string('continueattemptquiz', 'adaquiz');
        } else if ($canpreview) {
            $viewobj->buttontext = get_string('continuepreview', 'adaquiz');
        }

    } else {
        if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                    $viewobj->numattempts, $viewobj->lastfinishedattempt);
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptquiznow', 'adaquiz');
            } else {
                $viewobj->buttontext = get_string('reattemptquiz', 'adaquiz');
            }

        } else if ($canpreview) {
            $viewobj->buttontext = get_string('previewquiznow', 'adaquiz');
        }
    }

    // If, so far, we think a button should be printed, so check if they will be
    // allowed to access it.
    if ($viewobj->buttontext) {
        if (!$viewobj->moreattempts) {
            $viewobj->buttontext = '';
        } else if ($canattempt
                && $viewobj->preventmessages = $viewobj->accessmanager->prevent_access()) {
            $viewobj->buttontext = '';
        }
    }
}

$viewobj->showbacktocourse = ($viewobj->buttontext === '' &&
        course_get_format($course)->has_view_page());

echo $OUTPUT->header();

if (isguestuser()) {
    // Guests can't do an adaptive  quiz, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $adaquiz, $cm, $context, $viewobj->infomessages);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $adaquiz, $cm, $context, $viewobj->infomessages);
} else {
    echo $output->view_page($course, $adaquiz, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
