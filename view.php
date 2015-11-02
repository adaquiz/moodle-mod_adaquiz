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
// AdaptiveQuiz new capability
$canedit = has_capability('mod/adaquiz:manage', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$adaquizobj = \mod_adaquiz\wiris\Adaquiz::create($cm->instance, $USER->id);
$accessmanager = new adaquiz_access_manager($adaquizobj, $timenow,
        has_capability('mod/adaquiz:ignoretimelimits', $context, null, false));
$adaquiz = $adaquizobj->get_adaquiz();

// AdaptiveQuiz get attempts.

$unfinished = false;
$attemptobj = array();
if ($attempts = $adaquizobj->getAllAttempts($USER->id)){
    $count = 0;
    foreach($attempts as $attempt){
        // $attemptobj[$count] = adaquiz_attempt::create($attempt->id);
        // foreach ($attempt as $key => $value){
        //     $attemptobj[$count]->$key = $value;
        // }
        if ($attempt->get_state() == \mod_adaquiz\wiris\Attempt::STATE_ANSWERING){
            $unfinished = true;
        }
        $count++;
    }
}else{
    $attempts = null;
}


$title = $course->shortname . ': ' . format_string($adaquiz->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_adaquiz');

$viewobj = new mod_adaquiz_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine;

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    $viewobj->attemptcolumn  = true;
    $viewobj->gradecolumn    = true;
    $viewobj->markcolumn     = true;
    $viewobj->overallstats   = true;
    $viewobj->canreviewmine  = true;
    //$viewobj->feedbackcolumn = true;

    $myquizgrade = $adaquiz->grade;
    $mygrade = 0;
    foreach ($attempts as $key => $value){

        $myattemptsumgrades = $value->get_attempt()->sumgrades;
        $myroute = adaquiz_get_full_route($value->get_attemptid());
        $adaquiz = $value->get_adaquiz();
        $myquizsumgrades = adaquiz_get_real_sumgrades($value->get_attemptid(), $adaquiz->id, $myroute);
        if ($myquizsumgrades != 0) {
            $newgrade = $myattemptsumgrades * $myquizgrade / $myquizsumgrades;
        }
        else {
            $newgrade = 0;
        }
        if ($newgrade > $mygrade){
            $mygrade = $newgrade;
        }
    }
    $viewobj->mygrade  = $mygrade;
}
;
$viewobj->attempts = $attempts;
$viewobj->canedit = $canedit;
$viewobj->editurl = new moodle_url('/mod/adaquiz/edit.php', array('cmid' => $cm->id));
$viewobj->buttontext = '';
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->numAttempts = $adaquizobj->getNumAttempts();
$viewobj->startattempturl = new moodle_url('/mod/adaquiz/startattempt.php', array('id' => $id, 'cmid' => $cm->id, 'sesskey' => sesskey()));

$PAGE->set_url('/mod/adaquiz/view.php', array('id' => $cm->id));


// Determine wheter a start attempt button should be displayed.
$viewobj->adaquizhasquestions = (bool)$adaquizobj->getFirstNode();


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
            if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptquiznow', 'adaquiz');
            } else {
                $viewobj->buttontext = get_string('reattemptquiz', 'adaquiz');
            }

        } else if ($canpreview) {
            $viewobj->buttontext = get_string('previewquiznow', 'adaquiz');
        }
    }
}

echo $OUTPUT->header();
echo $output->view_page($course, $adaquiz, $cm, $context, $viewobj);
echo $OUTPUT->footer();