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
require_once($CFG->dirroot.'/mod/adaquiz/lib/Adaquiz.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot.'/mod/adaquiz/attemptlib.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

global $DB, $PAGE, $OUTPUT;
$decimalpoints = 2;

$id = optional_param('id',0, PARAM_INT); //Course Module ID, or
$aq = optional_param('aq',0, PARAM_INT); //adaquiz id

if ($id) {
    if (! $cm = get_coursemodule_from_id('adaquiz', $id)) {
        error('There is no coursemodule with id '.$id);
    }
    if (! $course = $DB->get_record('course', array('id' => $cm->course))) {
        error('Course is misconfigured');
    }

    $data = new stdClass();
    $data->id = $cm->instance;
    $adaquiz = new Adaquiz($data);
    $adaquiz->decimalpoints = $decimalpoints;
    $adaquiz->introformat = 1;

    if (!$adaquiz){
        error('The adaquiz with module id '.$cm->instance.' corresponding to this coursemodule '.$id.' is missing');
    }
}else if($aq){
    $data = new stdClass();
    $data->id = $aq;
    $adaquiz = new Adaquiz($data);
    $adaquiz->decimalpoints = $decimalpoints;

    if (!$adaquiz){
        error('The adaquiz with instance id '.$aq.' does not exist.');
    }
    if (!$course = $DB->get_record('course', array('id' => $adaquiz->course))){
        error('Course is misconfigured');
    }
    if(! $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id, $course->id)){
        error('There is no coursemodule with instance id '.$aq);
    }
}else{
    error('Missing id or aq');
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);

$canattempt = has_capability('mod/adaquiz:attempt', $context);
$canreviewmine = has_capability('mod/adaquiz:reviewmyattempts', $context);
$canpreview = has_capability('mod/adaquiz:preview', $context);
$canedit = has_capability('mod/adaquiz:manage', $context);

$unfinished = false;
$attemptobj = array();
if ($attempts = $adaquiz->getAllAttempts($USER->id)){
    $count = 0;
    foreach($attempts as $attempt){
        $attemptobj[$count] = adaquiz_attempt::create($attempt->id);
        foreach ($attempt as $key => $value){
            $attemptobj[$count]->$key = $value;
        }
        if ($attempt->state == Attempt::STATE_ANSWERING){
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
    foreach ($attemptobj as $key => $value){

        $myattemptsumgrades = $value->get_attempt()->sumgrades;
        $myroute = adaquiz_get_full_route($value->get_attemptid());
        $quiz = $value->get_quiz();
        $myquizsumgrades = adaquiz_get_real_sumgrades($value->id, $quiz->id, $myroute);
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

$viewobj->attempts = $attemptobj;
$viewobj->canedit = $canedit;
$viewobj->editurl = new moodle_url('/mod/adaquiz/edit.php', array('cmid' => $cm->id));
$viewobj->buttontext = '';
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->numAttempts = $adaquiz->getNumAttempts();
$viewobj->startattempturl = new moodle_url('/mod/adaquiz/startattempt.php', array('id' => $id, 'cmid' => $cm->id, 'sesskey' => sesskey()));

$PAGE->set_url('/mod/adaquiz/view.php', array('id' => $cm->id));


// Determine wheter a start attempt button should be displayed.
$viewobj->quizhasquestions = (bool)$adaquiz->getFirstNode();


if (!$viewobj->quizhasquestions) {
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