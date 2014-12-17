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

require_once($CFG->dirroot.'/lib/dmllib.php');
require_once($CFG->dirroot.'/lib/questionlib.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/NodeAttempt.php');

class Attempt {

  const TABLE = 'adaquiz_attempt';

  const STATE_ANSWERING  = 0;
  const STATE_REVIEWING  = 1;
  const STATE_FINISHED   = 2;
  //persistent data (saved in DB)
  var $id;
  var $uniqueid;
  var $userid;
  var $timecreated;
  var $timemodified;
  var $adaquiz; //adaquiz id
  var $preview; //0 or 1
  var $state; //
  var $seed; //random seed

  //calculated data
  var $nodeAttempts;
  var $adaquizobj;

  var $grade;
  var $maxgrade;
  var $gradeComputed = false;
  /**
   * @param mixed $data can be an identifier (integer), or an object with more data.
   * **/
  private function Attempt($adaquizobj){
    $this->adaquizobj = $adaquizobj;
    $this->adaquiz = $adaquizobj->id;
  }

  private function loadAttributes($data){
    if(isset($data->id)){
      $this->id = $data->id;
    }
    if(isset($data->uniqueid)){
      $this->uniqueid = $data->uniqueid;
    }
    if(isset($data->adaquiz)){
      $this->adaquiz = $data->adaquiz;
    }

    if(isset($data->preview)){
      $this->preview = $data->preview;
    }else{
      $this->preview = 0;
    }

    if(isset($data->userid)){
      $this->userid = $data->userid;
    }else{
      global $USER;
      $this->userid = $USER->id;
    }
    if(isset($data->timecreated)){
      $this->timecreated = $data->timecreated;
    }else{
      $this->timecreated = time();
    }
    if(isset($data->timemodified)){
      $this->timemodified = $data->timemodified;
    }
    if(isset($data->state)){
      $this->state = $data->state;
    }else{
      $this->state = Attempt::STATE_ANSWERING;
    }
    if(isset($data->seed)){
      $this->seed = $data->seed;
    }else{
      $this->seed = 0;
    }
  }

  private function save(){
    global $DB;
    $this->timemodified = time();
    if($this->id){
      $DB->update_record(Attempt::TABLE, $this);
    }else{
      $this->id = $DB->insert_record(Attempt::TABLE, $this);
    }
  }

  public function delete(){
    global $DB;
    //delete node attempts
    $this->loadNodeAttempts();
    foreach($this->nodeAttempts as $key=>$nodeattempt){
      $nodeattempt->delete();
      unset($this->nodeAttempts[$key]);//free memory
    }
    $DB->delete_records(Attempt::TABLE, array('id' => $this->id));
  }

  public function finish(){
    if(!$this->isFinished()){
      $this->state = Attempt::STATE_FINISHED;
    }
    $this->save();
    $this->adaquizobj->updateGrades($this->userid);
  }
  /**
   * @return bool
   * **/
  public function isEmpty(){
    $this->loadNodeAttempts();
    return count($this->nodeAttempts) == 0;
  }
  /**
   * @return bool
   * **/
  public function isFinished(){
    return $this->state == Attempt::STATE_FINISHED;
  }
  /**
   * @return NodeAttempt
   * **/
  public function &getCurrentNodeAttempt(){
    $this->loadNodeAttempts();
    if(count($this->nodeAttempts)==0){
      $this->nodeAttempts[] = $this->createFirstNodeAttempt();
    }
    $nodeattempt = end($this->nodeAttempts);
    return $nodeattempt;
  }
  public function &getNodeAttempts(){
    $this->loadNodeAttempts();
    return $this->nodeAttempts;
  }
  /**
   * @return string with a comma separated list of node positions in this attempt.
   */
  public function getNodePositionsList(){
    $natts = '';
    foreach($this->getNodeAttempts() as $pos=>$natt){
      if(!empty($natts)) $natts .= ', ';
      $natts .= ($this->adaquizobj->getNode($natt->node)->position +1);
    }
    return $natts;
  }

  public function getState(){
    return $this->state;
  }

  /**
   * @param NodeAttempt $nodeattempt
   * @return the last question state for given NodeAttempt;
   * **/
  public function &getQuestionState(&$nodeattempt){
    if(!$nodeattempt->question_state){
      $node = $this->adaquizobj->getNode($nodeattempt->node);
      $question = $node->getQuestion();
      $questions = array($question->id=>$question);
      $cmoptions = $this->adaquizobj->getCMOptions();
      $this->overrideCMOptions($cmoptions);
      $node->overrideCMOptions($cmoptions);
      $states = get_question_states($questions, $cmoptions, $nodeattempt);
      $state = $states[$question->id];

      $nodeattempt->question_state = $state;
      if(!isset($state->id) || !($state->id)){  //state is newly created
        //$nodeattempt->question_attempt = $nodeattempt->question_state->attempt;
        $nodeattempt->save();
        save_question_session($question, $nodeattempt->question_state);
      }
    }
    return $nodeattempt->question_state;
  }
  /**
   * loads nodeattempts, and if the Attempt is recently created, it also
   * creates the first nodeatempt.
   * **/
  private function loadNodeAttempts(){
    if(!$this->nodeAttempts && $this->id){
      $this->nodeAttempts = NodeAttempt::getAllNodeAttempts($this->id);
    }
  }

  private function createFirstNodeAttempt(){
    $node = Node::getFirstNode($this->adaquiz);
    $nodeattempt = NodeAttempt::createNodeAttempt($this->id, $node->id, 0);
    return $nodeattempt;
  }

  public function processQuestionResponse(&$data){
    $nodeattempt = & $this->getCurrentNodeAttempt();
    $node = & $this->adaquizobj->getNode($nodeattempt->node);
    $question = & $node->getQuestion();
    $state = & $this->getQuestionState($nodeattempt);

    $questions = array($question->id=>$question);
    $actions = question_extract_responses($questions, $data, QUESTION_EVENTSUBMIT);
    $action = $actions[$question->id];
    question_process_responses($question, $state, $action, $this->adaquizobj->getCMOptions(), $nodeattempt);
    save_question_session($question, $state);
    $this->state = Attempt::STATE_REVIEWING;
    $this->save();
  }

  public function createNextNodeAttempt($data = null){
    if($data == null) $data = new stdClass();
    $nodeattempt = & $this->getCurrentNodeAttempt();
    $node = & $this->adaquizobj->getNode($nodeattempt->node);
    $jump = & $node->getJump();
    //Perhaps this should be done outside, at PageAdaquiz.php?
    if($node->options[Node::OPTION_LETSTUDENTJUMP] && isset($data->next)){
      foreach($jump->getCaseNames() as $pos=>$name){
        if($name == $data->next){
          $case = $jump->singlejumps[$pos];
          break;
        }
      }
    }
    if(!isset($case)){
      $case = & $jump->getJumpCase($this);
    }
    $nodeattempt->jump = $case->id;
    $nodeattempt->save();
    if($case->nodeto){
      $node = & $this->adaquizobj->getNode($case->nodeto);
      $pos = count($this->nodeAttempts);
      $this->nodeAttempts[$pos]= NodeAttempt::createNodeAttempt($this->id, $node->id, $pos);
      $this->state = Attempt::STATE_ANSWERING;
      $this->save();
    }else{
      $this->finish(); //includes saving
    }
  }
  public function overrideCMOptions(&$cmoptions){
    $cmoptions->seed = $this->seed;
  }
  //////////////////////////////////////////////////////////////////////////////
  ///  DATA ACCESSED WHEN DECIDING WHAT NODE TO JUMP
  //////////////////////////////////////////////////////////////////////////////
  public function getNextJump($node, $fraction){
    global $DB;
    $records = $DB->get_records(Jump::TABLE, array('nodefrom' => $node->node), 'position ASC');

    foreach($records as $key => $j){
        if ($j->type == Jump::TYPE_UNCONDITIONAL){
            return $j;
        }else if ($j->type == Jump::TYPE_LASTGRADE){
            $options = unserialize($j->options);
            if ($options['cmp'] == '<'){
                if ($fraction < $options['value']){
                    return $j;
                }
            }else if ($options['cmp'] == '>'){
                if ($fraction > $options['value']){
                    return $j;
                }
            }
        }else if ($j->type == Jump::TYPE_FINISH_ADAQUIZ){
            return 0;
        }
    }
    return 0;
  }


  //return question grade as is in record $question_state->grade.
  public function getLastQuestionGrade(){
    $nodeattempt = &$this->getCurrentNodeAttempt();
    $state = & $this->getQuestionState($nodeattempt);
    return $state->grade;
  }

  // @return attempt grade attempt grade.
  public function getGrade(){
    if(!$this->gradeComputed){
      $this->computeGrade();
      $this->gradeComputed = true;
    }
    return $this->grade;
  }


  private function computeGrade(){
    $natts = $this->getNodeAttempts();
    $grade = 0.0;
    $maxgrade = 0.0;

    foreach($natts as $nodeattempt){
      $state = & $this->getQuestionState($nodeattempt);
      $node  = $this->adaquizobj->getNode($nodeattempt->node);
      $grade += $state->grade;

      $node = $this->adaquizobj->getNode($nodeattempt->node);
      $question = $node->getQuestion();

      $maxgrade += $question->maxgrade;
    }
    if($maxgrade == 0) $maxgrade = 1;
    $this->grade = ($grade / $maxgrade)* $this->adaquizobj->grade;
    $this->grade = round($this->grade * 100)/100;
  }
  //////////////////////////////////////////////////////////////////////////////
  ///  STATIC
  //////////////////////////////////////////////////////////////////////////////
  public static function getCurrentAttempt(&$adaquizobj, $userid, $preview = 0){
    global $DB;
    $record = $DB->get_record_select(Attempt::TABLE, ' adaquiz = '.$adaquizobj->id.' AND userid = '.$userid.' AND state != '.Attempt::STATE_FINISHED);
    if($record){
      $attempt = new Attempt($adaquizobj);
      $attempt->loadAttributes($record);
    }else{
      $attempts = Attempt::getAllAttempts($adaquizobj, $userid);
      foreach($attempts as $att){
          if($att->preview){//delete last previews when previewing.
          $att->delete();
          }
      }
      $attempt = Attempt::createNewAttempt($adaquizobj, $userid, $preview);
      $attempt->save();
    }
    return $attempt;
  }
  public static function getAllAttempts(&$adaquizobj, $userid = null){
    global $DB;
    $attempts = array();
    $where = 'adaquiz = '. $adaquizobj->id;
    if(is_number($userid)){
      $where .= ' AND userid = '. $userid;
    }
    if($records = $DB->get_records_select(Attempt::TABLE, $where)){
      foreach($records as $record){
        $attempt = new Attempt($adaquizobj);
        $attempt->loadAttributes($record);
        $attempts[]=$attempt;
      }
    }
    return $attempts;
  }
  /**
   * @warning it brakes array keys
   * **/
  public static function sortAttemptsByGrade(&$attempts){
    foreach($attempts as $key=>$attempt){
      unset($attempts[$key]);
      $attempts[$attempt->getGrade()] = $attempt;
    }
    ksort($attempts);
  }
  public static function sortAttemptsByUserName(&$attempts){
    $attemptsbyuser = array();
    foreach($attempts as $key=>$attempt){
      $account = get_record('user', 'id', $attempt->id);
      $attemptsbyuser[fullname($account).$attempt->id] = $attempt;
    }
    ksort($attemptsbyuser);
    $attempts = $attemptsbyuser;
  }

  public static function getAttemptById(&$adaquizobj, $attemptId){
    global $DB;
    $record = $DB->get_record(Attempt::TABLE, array('id' => $attemptId));
    $attempt = new Attempt($adaquizobj);
    $attempt->loadAttributes($record);
    return $attempt;
  }

  public static function getNumAttempts(&$adaquizobj){
    global $DB;
    return $DB->count_records(Attempt::TABLE, array('adaquiz' => $adaquizobj->id, 'preview' => 0));
  }

  private static function createNewAttempt(&$adaquizobj, $userid, $preview=0){
    $attempt = new Attempt($adaquizobj);
    $record = new stdClass();
    $record->userid = $userid;
    $record->preview = $preview;
    $record->uniqueid = -1;
    $record->seed = mt_rand(1,99991);
    $attempt->loadAttributes($record);
    return $attempt;
  }

}
?>
