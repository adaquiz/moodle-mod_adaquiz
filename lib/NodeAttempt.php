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

require_once($CFG->dirroot.'/lib/questionlib.php');
require_once($CFG->dirroot.'/lib/dmllib.php');
/**
 * this class is used only in order to have a confortable way to access its data,
 * but it does not contain functional content. See Attempt.php.
 * **/
class NodeAttempt {

  const TABLE = 'adaquiz_node_attempt';

  //persistent data
  var $id;
  var $uniqueid;
  var $attempt;
  var $node;
  var $timecreated;
  var $timemodified;
  var $timefinished;
  var $position;
  var $jump;
  //var $question_attempt;

  //calculated data
  var $question_state; //question state object

  var $timestart;      //alias for timecreated used by questionlib
  var $timefinish = 0;

  //////////////////////////////////////////////////////////////////////////////
  ///  PUBLIC
  //////////////////////////////////////////////////////////////////////////////
  private function NodeAttempt(){}


  public function save(){
    global $DB;
    $this->timemodified = time();
    if($this->id){
      //updating existing
      $DB->update_record(NodeAttempt::TABLE, $this);
      //delete node attempts from this attempt that go after the updated one
      if ($DB->delete_records_select(NodeAttempt::TABLE, 'position > ? AND attempt = ?', array($this->position, $this->attempt))) {
      	$subqueryquestionattempts = 'SELECT id FROM {question_attempts} WHERE questionusageid= ? AND slot> ?';
      	$subqueryquestionattemptstepdata = "SELECT id FROM {question_attempt_steps} WHERE questionattemptid IN ($subqueryquestionattempts)";
      	$adaquizattempt = $DB->get_record(Attempt::TABLE, array('id' => $this->attempt));
      	$params = array($adaquizattempt->uniqueid, $this->position+1);
      	//Delete records in question_attempt_step_data associated to the adaquiz_node_attempts deleted above
      	$DB->delete_records_select('question_attempt_step_data', "attemptstepid IN ($subqueryquestionattemptstepdata)", $params);
      	//Delete records in question_attempt_steps associated to the adaquiz_node_attempts deleted above
      	$DB->delete_records_select('question_attempt_steps', "questionattemptid IN ($subqueryquestionattempts)", $params);
      	//Delete records in question_attempts associated to the adaquiz_node_attempts deleted above
      	$DB->delete_records_select('question_attempts', 'questionusageid= ? AND slot> ?', $params);
      }
    }else{
      //add new
      $this->id = $DB->insert_record(NodeAttempt::TABLE, $this);
    }
  }
  public function delete(){
    global $DB;
    //delete question attempts
    //delete_attempt($this->uniqueid);
    $DB->delete_records(NodeAttempt::TABLE, array('id' => $this->id));
  }

  /*public function &getQuestionState(){
    return $this->question_state;
  }*/

  //////////////////////////////////////////////////////////////////////////////
  ///  PRIVATE
  //////////////////////////////////////////////////////////////////////////////

  private function loadFromRecord($record){
    $this->id = $record->id;
    $this->uniqueid = $record->uniqueid;
    $this->node = $record->node;
    $this->attempt = $record->attempt;
    $this->timecreated = $record->timecreated;
    $this->timestart = $this->timecreated;
    $this->timemodified = $record->timemodified;
    $this->position = $record->position;
    $this->jump = $record->jump;
    //$this->question_attempt = $record->question_attempt;
  }

  //////////////////////////////////////////////////////////////////////////////
  ///   PUBLIC STATIC
  //////////////////////////////////////////////////////////////////////////////
  public static function getNodeAttemptById($nodeattemptid){
    $nodeattempt = new NodeAttempt();
    $record = get_record(NodeAttempt::TABLE, 'id', $nodeattemptid);
    if($record == false) return false;
    $nodeattempt->loadFromRecord($record);
    return $nodeattempt;
  }
  public static function getNodeAttemptByUniqueId($unique){
    $nodeattempt = new NodeAttempt();
    $record = get_record(NodeAttempt::TABLE, 'uniqueid', $unique);
    if($record == false) return false;
    $nodeattempt->loadFromRecord($record);
    return $nodeattempt;
  }

  public static function getAllNodeAttempts($attemptid){
    global $DB;
    $records = $DB->get_records(NodeAttempt::TABLE, array('attempt' => $attemptid), 'position ASC');
    $attempts = array();
    if($records){
      foreach($records as $record){
        $attempt = new NodeAttempt();
        $attempt->loadFromRecord($record);
        $attempts[]=$attempt;
      }
    }
    return $attempts;
  }

  public static function getActualNodeAttempt($attemptid, $page = -1){
    global $DB;

    $conditions = array('attempt' => $attemptid);
    //If page is not set it will return the latest node attempt
    if ($page > -1) {
    	$conditions['position'] = $page;
    }
    $records = $DB->get_records(NodeAttempt::TABLE, $conditions, 'id DESC');
    $node = array_shift($records);

    $nodeattempt = new NodeAttempt();
    $nodeattempt->id = $node->id;
    $nodeattempt->attempt = $node->attempt;
    $nodeattempt->node = $node->node;
    $nodeattempt->position = $node->position;
    $nodeattempt->timecreated = $node->timecreated;
    //$nodeattempt->timestart = $node->timestart;
    $nodeattempt->jump = $node->jump;
    $nodeattempt->uniqueid = $node->uniqueid;

    return $nodeattempt;
  }

  public static function createNodeAttempt($attempt, $node, $position){
    $nodeattempt = new NodeAttempt();
    $nodeattempt->attempt = $attempt;
    $nodeattempt->node = $node;
    $nodeattempt->position = $position;
    $nodeattempt->timecreated = time();
    $nodeattempt->timestart = $nodeattempt->timecreated;
    $nodeattempt->jump = $nodeattempt->calculateNodeAttemptJump($node);
    //$nodeattempt->uniqueid = question_new_attempt_uniqueid('adaquiz');
    $nodeattempt->uniqueid = 0;
    return $nodeattempt;
  }


  public static function calculateNodeAttemptJump($node) {
  	global $DB;
  	$jump = null;
    // AQ-19
  	$record = $DB->get_records_sql("SELECT n.id, q.qtype, j.id AS jump, j.nodeto
    FROM {".Node::TABLE."} n, {question} q, {".Jump::TABLE."} j
    WHERE n.id = ? AND q.id=question AND j.nodefrom=n.id LIMIT 1" , array($node));
  	$questiontype = $record[$node]->qtype;
    if (NodeAttempt::is_automatic_jump_question($questiontype)) {
  		if ($record[$node]->nodeto == 0) {
  			//Last question
  			$jump = 0;
  		}
  		else {
  			$jump = $record[$node]->jump;
  		}
  	}

  	return $jump;
  }

    public static function is_automatic_jump_question($questiontype) {
    	$automaticjumpqtypes  = array("essay", "essaywiris", "description");
    	return in_array($questiontype, $automaticjumpqtypes);
    }

}
?>
