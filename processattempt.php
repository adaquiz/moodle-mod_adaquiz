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
 * This page deals with processing responses during an attempt at an adaptive quiz.
 *
 * People will normally arrive here from a form submission on attempt.php or
 * summary.php, and once the responses are processed, they will be redirected to
 * attempt.php or summary.php.
 *
 * This code used to be near the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_adaquiz
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz\wiris;

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

// Remember the current time as the time any responses were submitted
// (so as to make sure students don't get penalized for slow processing on this page).
$timenow = time();

// Get submitted parameters.
$attemptid     = required_param('attempt',  PARAM_INT);
$thispage      = optional_param('thispage', 0, PARAM_INT);
$nextpage      = optional_param('nextpage', 0, PARAM_INT);
$next          = optional_param('next',          false, PARAM_BOOL);
$finishattempt = optional_param('finishattempt', false, PARAM_BOOL);
$timeup        = optional_param('timeup',        0,      PARAM_BOOL); // True if form was submitted by timer.
$scrollpos     = optional_param('scrollpos',     '',     PARAM_RAW);
// AdaptiveQuiz params.
$slot          = optional_param('slot',  -1, PARAM_INT);
$summary       = optional_param('summary', false, PARAM_BOOL);
$removeid      = optional_param('remid', false, PARAM_BOOL);

// AdaptiveQuiz $nextpage.
$rawdata = (array) data_submitted();
$nparray = array();

foreach ($rawdata as $key => $value) {
    if (preg_match('!^fn([0-9]+)$!', $key, $matches)) {
        $pressedbutton = $matches[0];
    }
    if (preg_match('!^nextpage_fn([0-9]+)$!', $key, $matches)) {
        $nparray[$matches[0]] = $rawdata[$key];
    }
}
if (isset($pressedbutton)){
    $nextpage = $nparray['nextpage_'.$pressedbutton];
}

$transaction = $DB->start_delegated_transaction();

$attemptobj = Attempt::create($attemptid);
$adaquizobj = adaquiz::create($attemptobj->get_adaquizid());

// AdaptiveQuiz.
//This is used to avoid a bug with wirisquestions in creating the question
if ($removeid){
    unset($attemptid);
}

// If there is only a very small amount of time left, there is no point trying
// to show the student another page of the adaptive quiz. Just finish now.
$graceperiodmin = null;
$accessmanager = $attemptobj->get_access_manager($timenow);
$timeclose = $accessmanager->get_end_time($attemptobj->get_attempt());

// Don't enforce timeclose for previews
if ($attemptobj->is_preview()) {
    $timeclose = false;
}
$toolate = false;
if ($timeclose !== false && $timenow > $timeclose - ADAQUIZ_MIN_TIME_TO_CONTINUE) {
    $timeup = true;
    $graceperiodmin = get_config('adaquiz', 'graceperiodmin');
    if ($timenow > $timeclose + $graceperiodmin) {
        $toolate = true;
    }
}

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());
require_sesskey();

// Check that this attempt belongs to this user.
if ($attemptobj->get_userid() != $USER->id) {
    throw new \moodle_adaquiz_exception($attemptobj->get_adaquizobj(), 'notyourattempt');
}

// Check capabilities.
if (!$attemptobj->is_preview_user()) {
    $attemptobj->require_capability('mod/adaquiz:attempt');
}

// If the attempt is already closed, send them to the review page.
if ($attemptobj->is_finished()) {
    throw new \moodle_adaquiz_exception($attemptobj->get_adaquizobj(),
            'attemptalreadyclosed', null, $attemptobj->review_url());
}

// Don't log - we will end with a redirect to a page that is logged.

if (!$finishattempt) {
    if ($next || (isset($pressedbutton) && $nextpage != -1)){

        $na = $attemptobj->getCurrentNodeAttempt();
        if (isset($pressedbutton)){
            $na->jump = substr($pressedbutton, 2);
            $na->save();
        }

        //Create the this attempt from the jump
        if (!is_null($j = $na->jump)){
            $jump = $DB->get_record(Jump::TABLE, array('id' => $j));
            if ($jump->nodeto != -1){
                $na = NodeAttempt::createNodeAttempt($attemptobj->get_attemptid(), $jump->nodeto, $na->position+1);
                $na->save();
            }
        }

        $quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());

        $quizobj = adaquiz::create($attemptobj->get_adaquizid());
        $quizobj->preload_questions();
        $quizobj->load_questions();
        $questions = $quizobj->get_questions();

        $actualquestiondata = array_slice($questions, $nextpage, 1);
        $actualquestiondata = array_shift($actualquestiondata);
        $actualquestion = \question_bank::make_question($actualquestiondata);
        $quba->add_question($actualquestion, $actualquestiondata->maxmark);

        $attempts = adaquiz_get_user_attempts($quizobj->get_adaquizid(), $USER->id, 'all', true);
        $lastattempt = end($attempts);

        if ($lastattempt && !$lastattempt->preview) {
            $attemptnumber = count($attempts);
        } else {
            $attemptnumber = 1;
        }

        $node = $adaquizobj->getNode($na->node);
        $variantoffset = null;
        if ($node->options['commonrandomseed'] == 1){
            $variantoffset = $attemptobj->seed;
        }

        $ss = $quba->get_slots();
        $lastslot = end($ss);

        $quba->start_question($lastslot, $variantoffset);
        \question_engine::save_questions_usage_by_activity($quba);

        $transaction->allow_commit();

        //Just go to the next node (This node has been attempted and evaluated)

        redirect($attemptobj->adaquiz_attempt_url(null, $thispage+1));
    }
    if ($summary || (isset($pressedbutton) && $nextpage == -1)){
        if (isset($pressedbutton)){
            $na = $attemptobj->getCurrentNodeAttempt();
            $na->jump = 0;
            $na->save();
            $transaction->allow_commit();
        }
        redirect($attemptobj->summary_url($attemptobj->get_attemptid()));
    }
    try {
        $becomingoverdue = false;
        $attemptobj->process_submitted_actions($timenow, $becomingoverdue);
    } catch (\question_out_of_sequence_exception $e) {
        print_error('submissionoutofsequencefriendlymessage', 'question',
                $attemptobj->adaquiz_attempt_url(null, $thispage));

    } catch (\Exception $e) {
        $debuginfo = '';
        if (!empty($e->debuginfo)) {
            $debuginfo = $e->debuginfo;
        }
        print_error('errorprocessingresponses', 'question',
                $attemptobj->adaquiz_attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
    }
    $actualFraction = $attemptobj->get_question_attempt($slot)->get_fraction();
    $actualNodeAttempt = NodeAttempt::getActualNodeAttempt($attemptobj->get_attemptid(), $thispage);
    $actualnode = $adaquizobj->getNode($actualNodeAttempt->node);
    $jump = $attemptobj->getNextJump($actualNodeAttempt, $actualFraction*(float)$actualnode->grade);
    if (is_object($jump)){
        $summary = false;
        $actualNodeAttempt->jump = $jump->id;
    }else{
        $summary = true;
        $actualNodeAttempt->jump = 0;
    }
    $actualNodeAttempt->grade = $actualFraction;
    $actualNodeAttempt->save();
    $transaction->allow_commit();

    //Where it goes
    $nextpage = $thispage;

    if ($summary){
        $nextnode = -1;
    }else{
        //Set as nextpage for next button
        $actualNodeAttempt = NodeAttempt::getActualNodeAttempt($attemptobj->get_attemptid());
        $jump = $DB->get_record(Jump::TABLE, array('id' => $actualNodeAttempt->jump));
        $actualNode = Node::getNodeById($jump->nodeto);
        $nextnode = $actualNode->position;
    }

    redirect($attemptobj->adaquiz_attempt_url(null, $nextpage, null, $nextnode));
}

// Update the adaptive quiz attempt record.
try {
    $toolate = false;
    $attemptobj->process_finish($timenow, !$toolate);
    $transaction->allow_commit();
} catch (\question_out_of_sequence_exception $e) {
    print_error('submissionoutofsequencefriendlymessage', 'question',
            $attemptobj->adaquiz_attempt_url(null, $thispage));

} catch (\Exception $e) {
    // This sucks, if we display our own custom error message, there is no way
    // to display the original stack trace.
    $debuginfo = '';
    if (!empty($e->debuginfo)) {
        $debuginfo = $e->debuginfo;
    }
    print_error('errorprocessingresponses', 'question',
            $attemptobj->adaquiz_attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
}

redirect($attemptobj->review_url());
