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

// Remember the current time as the time any responses were submitted
$timenow = time();

// Get submitted parameters.
$attemptid     = required_param('attempt',  PARAM_INT);
$cmid          = required_param('cmid',  PARAM_INT);
$slot          = optional_param('slot',  -1, PARAM_INT);
$thispage      = optional_param('thispage', 0, PARAM_INT);
$nextpage      = optional_param('nextpage', 0, PARAM_INT);
$next          = optional_param('next',          false, PARAM_BOOL);
$finishattempt = optional_param('finishattempt', false, PARAM_BOOL);
$summary       = optional_param('summary', false, PARAM_BOOL);
$removeid      = optional_param('remid', false, PARAM_BOOL);

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

if (!$cm = get_coursemodule_from_id('adaquiz', $cmid)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$data = new stdClass();
$data->id = $cm->instance;
$adaquiz = new Adaquiz($data);

$attempt = $adaquiz->getCurrentAttempt($USER->id);
$attemptobj = adaquiz_attempt::create($attemptid);

//This is used to avoid a bug with wirisquestions in creating the question
if ($removeid){
    unset($attemptid);
}

$attempt->attemptobj = $attemptobj;
unset($attemptobj);

// Check login.
require_login($attempt->attemptobj->get_course(), false, $attempt->attemptobj->get_cm());
require_sesskey();

// Check that this attempt belongs to this user.
if ($attempt->attemptobj->get_userid() != $USER->id) {
    throw new moodle_quiz_exception($attempt->attemptobj->get_quizobj(), 'notyourattempt');
}

// Check capabilities.
if (!$attempt->attemptobj->is_preview_user()) {
    $attempt->attemptobj->require_capability('mod/adaquiz:attempt');
}

// If the attempt is already closed, send them to the review page.
if ($attempt->attemptobj->is_finished()) {
    throw new moodle_quiz_exception($attempt->attemptobj->get_quizobj(),
            'attemptalreadyclosed', null, $attempt->attemptobj->review_url());
}

if (!$finishattempt) {
    if ($next || (isset($pressedbutton) && $nextpage != -1)){
        $na = $attempt->getCurrentNodeAttempt();
        if (isset($pressedbutton)){
            $na->jump = substr($pressedbutton, 2);
            $na->save();
        }
        
        //Create the this attempt from the jump
        if (!is_null($j = $na->jump)){
            $jump = $DB->get_record(Jump::TABLE, array('id' => $j));
            if ($jump->nodeto != -1){
                $na = NodeAttempt::createNodeAttempt($attempt->id, $jump->nodeto, $na->position+1);
                $na->save();
            }
        }
        
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        
        $quizobj = quiz::create($cm->instance, $USER->id);
        $quizobj->preload_questions();
        $quizobj->load_questions();        
        $questions = $quizobj->get_questions();
        
        $actualquestiondata = array_slice($questions, $nextpage, 1);
        $actualquestiondata = array_shift($actualquestiondata);
        $actualquestion = question_bank::make_question($actualquestiondata);
        $quba->add_question($actualquestion, $actualquestiondata->maxmark);

        $attempts = adaquiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);
        $lastattempt = end($attempts);
        
        if ($lastattempt && !$lastattempt->preview) {
            $attemptnumber = count($attempts);
        } else {
            $attemptnumber = 1;
        }
        
        $node = $adaquiz->getNode($na->node);
        $variantoffset = null;
        if ($node->options['commonrandomseed'] == 1){
            $variantoffset = $attempt->seed;
        }

        $ss = $quba->get_slots();
        $lastslot = end($ss);
        
        $quba->start_question($lastslot, $variantoffset);
        question_engine::save_questions_usage_by_activity($quba);
        
        $transaction->allow_commit();        
        
        //Just go to the next node (This node has been attempted and evaluated)
        redirect($attempt->attemptobj->attempt_url(null, $thispage+1));
    }
    if ($summary || (isset($pressedbutton) && $nextpage == -1)){
        if (isset($pressedbutton)){
            $na = $attempt->getCurrentNodeAttempt();
            $na->jump = 0;
            $na->save();
            $transaction->allow_commit();
        }
        redirect($attempt->attemptobj->summary_url($cmid));
    }
    try {
        $becomingoverdue = false;
        $attempt->attemptobj->process_submitted_actions($timenow, $becomingoverdue);
    } catch (question_out_of_sequence_exception $e) {
        print_error('submissionoutofsequencefriendlymessage', 'question',
                $attempt->attemptobj->attempt_url(null, $thispage));

    } catch (Exception $e) {
        $debuginfo = '';
        if (!empty($e->debuginfo)) {
            $debuginfo = $e->debuginfo;
        }
        print_error('errorprocessingresponses', 'question',
                $attempt->attemptobj->attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
    }

    $actualFraction = $attempt->attemptobj->get_question_attempt($slot)->get_fraction();
    $actualNodeAttempt = NodeAttempt::getActualNodeAttempt($attempt->id, $thispage);
    $actualnode = $adaquiz->getNode($actualNodeAttempt->node);
    $jump = $attempt->getNextJump($actualNodeAttempt, $actualFraction*(float)$actualnode->grade);
    
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
        $actualNodeAttempt = NodeAttempt::getActualNodeAttempt($attempt->id);
        $jump = $DB->get_record(Jump::TABLE, array('id' => $actualNodeAttempt->jump));        
        $actualNode = Node::getNodeById($jump->nodeto);
        $nextnode = $actualNode->position;
        //$actualNodeAttempt = NodeAttempt::getActualNodeAttempt($attempt->id);
        //$nextnode = $actualNodeAttempt->position + 1;
    }
    
    redirect($attempt->attemptobj->attempt_url(null, $nextpage, null, $nextnode));
}
try{
    $toolate = false;
    $attempt->attemptobj->process_finish($timenow, !$toolate);       
    $transaction->allow_commit();
}catch (question_out_of_sequence_exception $e) {
    print_error('submissionoutofsequencefriendlymessage', 'question',
            $attempt->attemptobj->attempt_url(null, $thispage));
} catch (Exception $e) {
    $debuginfo = '';
    if (!empty($e->debuginfo)) {
        $debuginfo = $e->debuginfo;
    }
    print_error('errorprocessingresponses', 'question',
            $attempt->attemptobj->attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
}        

redirect($attempt->attemptobj->review_url());