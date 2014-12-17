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

function clean_all_previews($adaquiz){
    adaquiz_delete_previews($adaquiz);
    $attempts = Attempt::getAllAttempts($adaquiz);
    foreach($attempts as $att){
        if($att->preview){//delete last previews when previewing.
        $att->delete();
        }
    }
}

// Get submitted parameters.
$cmid = required_param('cmid', PARAM_INT); // Course module id
$page = optional_param('page', 0, PARAM_INT); // Page to jump to in the attempt.
$forcenew = optional_param('forcenew', 0, PARAM_INT); // Used to force a new attempt before it's closed.

if (!$cm = get_coursemodule_from_id('adaquiz', $cmid)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$data = new stdClass();
$data->id = $cm->instance;
$adaquiz = new Adaquiz($data);
$quizobj = quiz::create($cm->instance, $USER->id);
$adaquiz->quizobj = $quizobj;
unset($quizobj);

// This script should only ever be posted to, so set page URL to the view page.
$PAGE->set_url($adaquiz->quizobj->view_url());

// Check login and sesskey.
require_login($adaquiz->quizobj->get_course(), false, $adaquiz->quizobj->get_cm());
require_sesskey();

// If no questions have been set up yet redirect to edit.php or display an error.
if (!$adaquiz->quizobj->has_questions()) {
    if ($adaquiz->quizobj->has_capability('mod/adaquiz:manage')) {
        redirect($adaquiz->quizobj->edit_url());
    } else {
        print_error('cannotstartnoquestions', 'adaquiz', $adaquiz->quizobj->view_url());
    }
}

// Check capabilities.
if (!$adaquiz->quizobj->is_preview_user()) {
    $adaquiz->quizobj->require_capability('mod/adaquiz:attempt');
}

// Look for an existing attempt.
$attempts = adaquiz_get_user_attempts($adaquiz->quizobj->get_quizid(), $USER->id, 'all', true);
$lastattempt = end($attempts);

if ($lastattempt && $forcenew){
    clean_all_previews($adaquiz);
    $lastattempt = null;
}

if ($lastattempt && ($lastattempt->state == Attempt::STATE_ANSWERING)) {
    $currentattemptid = $lastattempt->id;
    $pagedata = adaquiz_get_last_attempted_page_data($currentattemptid);
    $page = $pagedata[0];
    $nextnode = $pagedata[1];
} else {
    // Get number for the next or unfinished attempt.
    if ($lastattempt && !$lastattempt->preview && !$adaquiz->quizobj->is_preview_user()) {
        $attemptnumber = count($attempts);
    } else {
        $lastattempt = false;
        $attemptnumber = 1;
    }
    $currentattemptid = null;
    if ($page == -1) {
        $page = 0;
    }
    $nextnode = null;
}

if ($currentattemptid) {
    redirect($adaquiz->quizobj->attempt_url($currentattemptid, $page, $cmid, $nextnode));
}

// Delete any previous preview attempts in question_attempts belonging to this user.
adaquiz_delete_previews($adaquiz->quizobj->get_quiz(), $USER->id);

$quba = question_engine::make_questions_usage_by_activity('mod_adaquiz', $adaquiz->quizobj->get_context());
//$quba->set_preferred_behaviour('immediatefeedback');
$preferredbehaviour = isset($adaquiz->options['preferredbehaviour'])?$adaquiz->options['preferredbehaviour']:ADAQUIZ_QUESTIONBEHAVIOUR;
$quba->set_preferred_behaviour($preferredbehaviour);

// Create the new attempt and initialize the question sessions
$attempt = $adaquiz->getCurrentAttempt($USER->id, $adaquiz->quizobj->is_preview_user());

$adaquiz->quizobj->preload_questions();
$adaquiz->quizobj->load_questions();

$questions = $adaquiz->quizobj->get_questions();
$firstquestiondata = array_shift($questions);
$firstquestion = question_bank::make_question($firstquestiondata);
$quba->add_question($firstquestion, $firstquestiondata->maxmark);

$transaction = $DB->start_delegated_transaction();
//Create the first node attempt
$firstNode = $adaquiz->getFirstNode();
$position = 0;
$na = NodeAttempt::createNodeAttempt($attempt->id, $firstNode->id, $position);
$na->save();

$node = $adaquiz->getNode($na->node);
$variantoffset = null;
if ($node->options['commonrandomseed'] == 1){
    $variantoffset = $attempt->seed;
}

$ss = $quba->get_slots();
$lastslot = end($ss);

$quba->start_question($lastslot, $variantoffset);

// Save the attempt in the database.
question_engine::save_questions_usage_by_activity($quba);

$attempt->uniqueid = $quba->get_id();
//We add the uniqueid
$DB->update_record('adaquiz_attempt', $attempt);

$transaction->allow_commit();

$pagedata = adaquiz_get_last_attempted_page_data($attempt->id);
$nextnode = $pagedata[1];

// Redirect to the attempt page.
redirect($adaquiz->quizobj->attempt_url($attempt->id, $page, $cm->id, $nextnode));