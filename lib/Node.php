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

/**
 * Description of node
 */
require_once($CFG->dirroot.'/lib/dmllib.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/Jump.php');
class Node {

  const TABLE = 'adaquiz_node';

  const OPTION_LETSTUDENTJUMP   = 'letstudentdecidejump';
  const OPTION_COMMONRANDOMSEED = 'commonrandomseed';

  var $id;
  var $question;
  var $questionobj; //for performance purposes, we also keep the question object.
  var $adaquiz;
  var $position;
  var $options;
  var $jump;
  var $grade;

  private function loadFromRecord($record){
    $this->id       = $record->id;
    $this->question = $record->question;
    $this->adaquiz  = $record->adaquiz;
    $this->position = $record->position;
    if($record->options){
      $this->options = unserialize($record->options);
    }else{
      $this->options = array();
    }
    $this->grade = $record->grade;
    $this->jump = null; //will be loaded if required.
  }

  /**
   * Lazy loading of jumps.
   * **/
  public function &getJump(){
    if($this->jump == null){
      $this->jump = Jump::getJump($this->id);
    }
    return $this->jump;
  }


  /**
   * Saves this node. Does not save the jumps.
   * **/
  public function save(){
    global $DB;
    $optionsArray = $this->options;
    $this->options = serialize($optionsArray);

    if($this->id){
      $DB->update_record(Node::TABLE, $this);
    }else{
      $this->id = $DB->insert_record(Node::TABLE, $this);
    }

    $this->options = $optionsArray;
    return $this->id;
  }

  public function delete(){
    global $DB;
    $ok = Jump::delete($this->id);
    return $DB->delete_records(Node::TABLE, array('id' => $this->id)) && $ok;
  }

  public function &getQuestion(){
    global $DB;
    if(!$this->questionobj){
      if($this->question){
        $this->questionobj = $DB->get_record('question', array('id' => $this->question));
        get_question_options($this->questionobj);
        $this->setCustomQuestionOptions();
      }else{
        $this->questionobj = new stdClass();
      }
    }
    return $this->questionobj;
  }

  /**
   * @param $opts an associative array of options.
   * **/
  public function updateOptions($opts){
    if($this->options != $opts){
      $this->options = $opts;
      $this->save();
    }
  }


  //add options to this question relative to this node.
  private function setCustomQuestionOptions(){
    $this->questionobj->maxgrade = $this->grade;
  }
  public function overrideCMOptions(&$cmoptions){
    if($this->options[Node::OPTION_COMMONRANDOMSEED]){
      $cmoptions->commonseed = true;
    }
  }
  // The following static functions are helper functons wich deal with the set
  // of Nodes that belong to one adaquiz.


  /**
   * @return a Node object with default options and without any jump.
   * **/
  public static function createDefaultNode() {
    $n = new Node();
    $n->options = array(
      Node::OPTION_COMMONRANDOMSEED=>false,
      Node::OPTION_LETSTUDENTJUMP=>false,
    );
    $n->jump = null;
    return $n;
  }

  public static function &getFirstNode($adaquizid){
    global $DB;
    $records = $DB->get_records(Node::TABLE, array('adaquiz' => $adaquizid), 'position ASC', '*', '0', '1');
    if(!empty($records)){
      $node = new Node();
      $node->loadFromRecord(reset($records));
      return $node;
    }
    $false = false;
    return $false;
  }

  public static function &getNodeById($nodeid){
    global $DB;
    $record = $DB->get_record(Node::TABLE, array('id' => $nodeid));

    if(!empty($record)){
      $node = new Node();
      $node->loadFromRecord($record);
      return $node;
    }
    $false = false;
    return $false;
  }

  public static function getAllNodes($adaquizid){
    global $DB;
    $records = $DB->get_records(Node::TABLE, array('adaquiz' => $adaquizid), 'position ASC');
    $nodes = array();
    if($records){
      foreach($records as $record){
        $node = new Node();
        $node->loadFromRecord($record);
        $nodes[]=$node;
      }
    }
    Node::loadQuestions($nodes);
    return $nodes;
  }

  private static function loadQuestions(&$nodes){
    global $CFG, $DB;
    if(!empty($nodes)){
      $quids = array();
      foreach($nodes as $key=>$node){
        $quids[$key] = $node->question;
      }
      $qidsstr = implode(',', $quids);
      $SQL = 'SELECT * FROM '.$CFG->prefix.'question WHERE id IN ('.$qidsstr.')';
      $records = $DB->get_records_sql($SQL);
      get_question_options($records);  //question-type specific questions

      foreach($nodes as $key=>$node){
        $nodes[$key]->questionobj = $records[$node->question];
        $nodes[$key]->setCustomQuestionOptions();
      }
    }
  }
  public static function deleteAllNodes($adaquizid){
    global $DB;
    $ok = true;
    $records = $DB->get_records(Node::TABLE, array('adaquiz' => $adaquizid), '', 'id');
    if($records){
      foreach($records as $record){
        $nodeid = $record->id;
        //delete all jumps associated to this node:
        $ok = Jump::delete($nodeid) && $ok;
      }
      //delete all nodes.
      $ok = $DB->delete_records(Node::TABLE, array('adaquiz' => $adaquizid)) && $ok;
    }
    return $ok;
  }



}
?>
