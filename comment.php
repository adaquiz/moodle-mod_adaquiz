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

require_once('../../config.php');
global $CFG;
require_once($CFG->dirroot.'/mod/adaquiz/lib/Adaquiz.php');

/**
 * This is almost a copy of question_process_comment() in questionlib.php but a
 * quiz-module specific code.
 * **/
function adaquiz_process_comment(&$question, &$state, &$attempt, $comment, $grade){
  $grade = trim($grade);
  if($grade < 0 || $grade > $question->maxgrade){
    $a = new stdClass;
    $a->grade = $grade;
    $a->maxgrade = $question->maxgrade;
    $a->name = $question->name;
    return get_string('errormanualgradeoutofrange', 'question', $a);
  }
  // Update the comment and save it in the database
  $comment = trim($comment);
  $state->manualcomment = $comment;
  if (!set_field('question_sessions', 'manualcomment', $comment, 'attemptid', $attempt->uniqueid, 'questionid', $question->id)) {
    return get_string('errorsavingcomment', 'question', $question);
  }
  if ($grade !== '' && (abs($state->last_graded->grade - $grade) > 0.002
     || $state->last_graded->event != QUESTION_EVENTMANUALGRADE)) {
    // We want to update existing state (rather than creating new one) if it
    // was itself created by a manual grading event.
    $state->update = $state->event == QUESTION_EVENTMANUALGRADE;
    // Update the other parts of the state object.
    $state->raw_grade = $grade;
    $state->grade = $grade;
    $state->penalty = 0;
    $state->timestamp = time();
    $state->seq_number++;
    $state->event = QUESTION_EVENTMANUALGRADE;

    // Update the last graded state (don't simplify!)
    unset($state->last_graded);
    $state->last_graded = clone($state);

    // We need to indicate that the state has changed in order for it to be saved.
    $state->changed = 1;
  }

}


  $uniqueid =required_param('attempt', PARAM_INT); // attempt id
  $questionid =required_param('question', PARAM_INT); // question id

  if (! $nodeattempt = NodeAttempt::getNodeAttemptByUniqueId($uniqueid)) {
    error('No such attempt ID exists');
  }

  if(! $adaId = get_field(Node::TABLE, 'adaquiz', 'id', $nodeattempt->node)){
     error('Course module is incorrect');
  }
  $adaquiz = new Adaquiz(intval($adaId));

  if (! $course = get_record('course', 'id', $adaquiz->course)) {
    error('Course is misconfigured');
  }

  $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id);
  require_login($course, true, $cm);

  $context = context_module::instance($cm->id);

  require_capability('mod/quiz:grade', $context);

  // Load question
  $attempt = Attempt::getAttemptById($adaquiz, $nodeattempt->attempt);
  $node = $adaquiz->getNode($nodeattempt->node);
  $question = $node->getQuestion();
  $state = $attempt->getQuestionState($nodeattempt);

  print_header();
  print_heading(format_string($question->name));

  if (($data = data_submitted()) && confirm_sesskey()) {
    
    $comment = $data->response['comment'];
    $grade   = $data->response['grade'];

    $error = adaquiz_process_comment($question, $state, $nodeattempt, $comment, $grade);
    if (is_string($error)) {
      notify($error);
    } else {
      // If the state has changed save it and update the quiz grade
      if ($state->changed) {
        save_question_session($question, $state);
        //update gradebook
        $adaquiz->updateGrades($attempt->userid);
      }
      notify(get_string('changessaved'));
      echo '<div style="text-align: center;"><input type="button" onclick="window.opener.location.reload(1); self.close();return false;" value="' .
           get_string('closewindow') . "\" /></div>";
    }
  }else{
    question_print_comment_box($question, $state, $nodeattempt, $CFG->wwwroot.'/mod/adaquiz/comment.php');
  }
  
  print_footer('empty');
