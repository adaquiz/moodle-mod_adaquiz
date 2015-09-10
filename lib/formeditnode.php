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

require_once($CFG->libdir.'/weblib.php');
require_once ($CFG->libdir.'/formslib.php');

define('RANDOM', 'random');

/**
 * This class extends moodleform only for using its helper functions on creating
 * elements, but the full workflow of moodle forms is not used.
 * **/
class FormEditNode extends moodleform{
  var $adaquiz;
  var $node;
  var $nodeslist;
  var $messages;
  function FormEditNode($action=null, $customdata=null) {
    $this->adaquiz = $customdata['adaquiz'];
    $this->node = $customdata['node'];
    $this->messages = array();
    parent::moodleform($action, $customdata);
  }
  public function definition(){
    $mform = $this->_form;
    $mform->addElement('hidden', 'nid', $this->node->id, 'id="nid"');
    $mform->setType('nid', PARAM_INT);
    $mform->addElement('hidden', 'aq', $this->adaquiz->id);
    $mform->setType('aq', PARAM_INT);
    $mform->addElement('html', '<input type="hidden" id="id_jump_string" value="'.$this->adaquiz->getJumpString($this->node->id).'" />');
    $this->defineMessages();
    $this->defineTitle();
    $this->defineGeneralOptions();
    $this->defineJump();
    $this->add_action_buttons();
  }
  public function addMessage($str){
    $this->messages[] = $str;
  }
  public function explicitDefinition(){
    // $mform = &$this->_form;
    // $mform->addElement('hidden', 'nid', $this->node->id, 'id="nid"');
    // $mform->addElement('hidden', 'aq', $this->adaquiz->id);
    // $mform->addElement('html', '<input type="hidden" id="id_jump_string" value="'.$this->adaquiz->getJumpString($this->node->id).'" />');
    // $this->defineMessages();
    // $this->defineTitle();
    // $this->defineGeneralOptions();
    // $this->defineJump();
    // $this->add_action_buttons();
  }
  /**
   * overwrites the default is_cancelled.
   * **/
  public function is_cancelled(){
    return (optional_param('cancel','', PARAM_RAW)!= '');
  }

  public function is_submitted(){
    return (optional_param('submitbutton','', PARAM_RAW)!= '');
  }
  private function defineMessages(){
    $mform = &$this->_form;
    if(!empty($this->messages)){
      $mform->addElement('html','<div class="error"><ul>');
      foreach($this->messages as $message){
        $mform->addElement('html','<li><span class="error">'.$message.'</span></li>');
      }
      $mform->addElement('html','</ul></div>');
    }
  }
  private function defineTitle(){
    global $OUTPUT;
    $mform = &$this->_form;
    $heading = get_string('editingnode', 'adaquiz') . ' ' . ($this->node->position +1)
      . ' ' . shorten_text($this->node->getQuestion()->name, 30);
    $html = $OUTPUT->heading_with_help($heading, 'editnode', 'adaquiz');
    $mform->addElement('html',$html, '', 2, 'main', true);

  }

  private function isWirisQuizzesQuestion(&$question){
    return substr($question->qtype,-5) == 'wiris';
  }
  private function needsSeedOption(&$question){
    if($this->isWirisQuizzesQuestion($question)) return true;
    global $CFG,$QTYPES;
    if($question->qtype == RANDOM){
      $questionIds = $QTYPES[$question->qtype]->get_usable_questions_from_category($question->category,
                    $question->questiontext == "1", '0');

      $questions = get_records_sql('SELECT * FROM '.$CFG->prefix.
        'question WHERE id IN ('.implode(',', array_keys($questionIds)).')');
      if($questions){
        get_question_options($questions);
        foreach($questions as $subquestion){
          if($this->needsSeedOption($subquestion)) return true;
        }
      }
    }
    return false;
  }

  private function defineGeneralOptions(){
    global $QTYPES;

    $mform = &$this->_form;
    $mform->addElement('header', 'generaloptions', get_string('nodeoptions', 'adaquiz'));
    $mform->addElement('html', '<div class="generaloptions">');
    $question = & $this->node->getQuestion();
    if($this->needsSeedOption($question)){
      $mform->addElement('checkbox',Node::OPTION_COMMONRANDOMSEED, get_string(Node::OPTION_COMMONRANDOMSEED, 'adaquiz'));
      $mform->setDefault(Node::OPTION_COMMONRANDOMSEED, $this->node->options[Node::OPTION_COMMONRANDOMSEED]);
    }
    $mform->addElement('checkbox',Node::OPTION_LETSTUDENTJUMP, get_string(Node::OPTION_LETSTUDENTJUMP, 'adaquiz'));
    if($this->canAutomaticJump($question)){
      //automatically graded
      $mform->setDefault(Node::OPTION_LETSTUDENTJUMP, $this->node->options[Node::OPTION_LETSTUDENTJUMP]);
    }else{
      //essay, description
      $mform->setDefault(Node::OPTION_LETSTUDENTJUMP, true);
      $mform->freeze(Node::OPTION_LETSTUDENTJUMP);
    }
    $mform->addElement('html', '</div>');
  }

  private function canAutomaticJump(&$question){
    //We are extremely careful about the existence of methods is_question_manual_graded
    //and actual_number_of_questions because they were introduced in 1.9.9 (and
    //UOC does not have them).

    global $QTYPES;
    if(method_exists($QTYPES[$question->qtype],'is_question_manual_graded')){
      if($QTYPES[$question->qtype]->is_question_manual_graded($question, '')){//manual graded
        return false;
      }
    }
    if(method_exists($QTYPES[$question->qtype],'actual_number_of_questions')){
      if($QTYPES[$question->qtype]->actual_number_of_questions($question)==0){//description
        return false;
      }
    }
    if($question->qtype == 'essay' || $question->qtype == 'essaywiris' || $question->qtype == 'description'){
      return false;
    }
    return true;
  }

  private function defineJump(){
    $mform = &$this->_form;
    $mform->addElement('header', 'jump', get_string('jump', 'adaquiz'));
    //print defined jumps:
    $this->defineJumpsTable();
    //print addCase
    $this->defineAddCase();
  }
  /**
   * The whole jumps table is a single html element, because of the limitations
   * of forms lib.
   * **/
  private function defineJumpsTable(){
    global $CFG, $OUTPUT;
    $html = '';
    $mform = &$this->_form;
    $jump = $this->node->getJump();
    $edit = $this->editJumpAction(); //position in {0,...,n} or false
    if($edit !== false){
      $html .= '<input type="hidden" name="update" value="'.$edit.'"/>';
    }
    $html .= '<table class="jumpcases" />';
    $html .= '<tr>';
    $html .= '<th colspan="2" class="header order" scope="col">'.get_string('order', 'adaquiz').'</th>';
    $html .= '<th class="header num" scope="col"></th>'; //position
    $html .= '<th class="header" scope="col">'.get_string('name').'</th>';
    $html .= '<th class="header" scope="col">'.get_string('type', 'adaquiz').'</th>';
    $html .= '<th class="header" scope="col">'.get_string('condition', 'adaquiz').'</th>';
    $html .= '<th class="header" scope="col">'.get_string('goto', 'adaquiz').'</th>';
    $html .= '<th class="header" scope="col">'.get_string('action', 'adaquiz').'</th>';
    $html .= '</tr>';
    //prepare data:
    $cases = array();

    //build abstract table

    foreach($jump->singlejumps as $singlejump){ //from position = 0 to position = n increasing by 1.
      $row = array();
      $pos =
      $name = $jump->getCaseName($singlejump);
      $row['position'] = $singlejump->position + 1;
      //
      if($edit === intval($singlejump->position)){

        $row['name'] = '<input type="text" name="update_name" value="'.$name.'" size="10"/>'.
                       '<input type="hidden" name="update_id" value="'.$singlejump->id.'"/>';
        $row['position'] .= '<input type="hidden" name="update_position" value="'.$singlejump->position.'" />';
        $types = $this->getJumpTypesList();
        $row['type'] = $this->printSelect('update_type', $types, 'onchange="changeCaseType()"', $singlejump->type);
        //$row['type'] .= '<input type="hidden" name="update_type" value="'.$singlejump->type.'" />';
        $row['condition'] = '';
        foreach($this->getJumpTypesList() as $type=>$str){
          $display = $type == $singlejump->type?'block':'block';
          $row['condition'] .= '<div class="casecondition '.$type.'" style="display: '.$display.';">'
                            .$this->conditionHTMLInput($type, 'update', $type==$singlejump->type?$singlejump:null).'</div>';
        }

        //$this->conditionHTMLInput($singlejump->type, 'update', $singlejump);
        $nodetodisp = ($singlejump->type == Jump::TYPE_FINISH_ADAQUIZ)?'none':'block';
        $row['nodeto'] = $this->printSelect('update_nodeto', $this->getNodesList(), 'style="display:'.$nodetodisp.';"', $singlejump->nodeto);
      }else{
        $row['name'] = $name;
        $row['condition'] = $jump->getConditionString($singlejump);
        $row['type'] = get_string($singlejump->type, 'adaquiz');
        if($singlejump->nodeto){
          $nodes = $this->getNodesList();
          $row['nodeto'] = shorten_text($nodes[$singlejump->nodeto],25);
        }else{
          $row['nodeto'] = get_string('end', 'adaquiz');
        }
      }

      $cases[$singlejump->position] = $row;
    }
    //print abstract table in HTML
    foreach($cases as $position=>$case){
      $html .= '<tr>';
      $html .= '<td>';
      if ($position != 0) {
        $html .= '<a title="'.get_string('moveup').'" href="editnode.php?nid='.$this->node->id.'&aq='.$this->adaquiz->id.'&moveup='.$position.'">' .
            //<img src="'.$CFG->pixpath.'/t/up.gif" class="iconsmall" alt="'.get_string('moveup').'" /></a>';
            $OUTPUT->pix_icon('/t/up', get_string('moveup'));
      }
      $html .= '</td>';
      $html .= '<td>';
      if ($position < count($cases)-1) {
        $html .= '<a title="'.get_string("movedown").'" href="editnode.php?nid='.$this->node->id.'&aq='.$this->adaquiz->id.'&movedown='.$position.'">' .
            $OUTPUT->pix_icon('/t/down', get_string('movedown'));
            //<img src="'.$CFG->pixpath.'/t/down.gif" class="iconsmall" alt="'.get_string('movedown').'" /></a>';
      }
      $html .= '</td>';
      $html .= '<td>'.$case['position'].'</td>';
      $html .= '<td>'.$case['name'].'</td>';
      $html .= '<td>'.$case['type'].'</td>';
      $html .= '<td>'.$case['condition'].'</td>';
      $html .= '<td>'.$case['nodeto'].'</td>';
      $html .= '<td>';
      $html .= '<a title="'.get_string('edit').'" href="editnode.php?nid='.$this->node->id.'&aq='.$this->adaquiz->id.'&edit='.$position.'"\>'.
                $OUTPUT->pix_icon('/t/edit', get_string('edit'));
      $html .= '<a title="'.get_string('remove', 'adaquiz').'" href="editnode.php?nid='.$this->node->id.'&aq='.$this->adaquiz->id.'&delete='.$position.'"\>'.
                $OUTPUT->pix_icon('/t/delete', get_string('remove', 'adaquiz'));
      $html .= '</td>';
      $html .= '</tr>';
    }

    $html .= '</table>';

    $mform->addElement('html', $html);
  }

  private function printSelect($name, $options, $atts='', $selected = ''){
    $html = '<select id="id_'.$name.'" name="'.$name.'" '. $atts.'>';
    foreach($options as $value=>$str){
      $sel = ($value == $selected)?'selected="selected"':'';
      $html .= '<option value="'.$value.'" '.$sel.'>'.shorten_text($str,25).'</option>';
    }
    $html .= '</select>';
    return $html;
  }
  private function getNodesList($length = 30){
    if($this->nodeslist == null){
      $this->nodeslist = array();
      foreach($this->adaquiz->nodes as $node){
        $this->nodeslist[$node->id] = shorten_text(($node->position +1) . ' '. $node->getQuestion()->name, $length);
      }
    }
    return $this->nodeslist;
  }
  private function getJumpTypesList(){
    $types = Jump::getJumpTypes();
    $list = array();
    foreach($types as $type){
      $list[$type] = get_string($type, 'adaquiz');
    }
    return $list;
  }

  private function defineAddCase(){
    $mform = &$this->_form;
    $html = '<div class="addcase">';
    $html .= '<label><strong>'.get_string('addcase', 'adaquiz').': </strong></label>';
    $types = $this->getJumpTypesList();
    $options = $types;
    $options[0] = get_string('choose').'...';
    ksort($options);
    //$atts = 'onchange="displayAddCase()"';
    $atts = '';
    $html .= $this->printSelect('addtype', $options, $atts);
    $html .= '</div>';
    $mform->addElement('html', $html);

    foreach($types as $type=>$str){
      $mform->addElement('html', '<div class="newcase '.$type.'">');
      $mform->addElement('text', 'add'.$type.'_name', get_string('name').':', array('size'=>'10'));
      $mform->setType('add'.$type.'_name', PARAM_RAW);
      $mform->setDefault('add'.$type.'_name', Jump::getDefaultName($type));
      $html  = '<div class="fitem"><div class="fitemtitle"><label>'.get_string('condition', 'adaquiz').':'.'</label></div>';
      $html .= '<div class="felement">'.$this->conditionHTMLInput($type, 'add'.$type).'</div></div>';
      $mform->addElement('html', $html);
      if($type!=Jump::TYPE_FINISH_ADAQUIZ){
        $mform->addElement('select', 'add'.$type.'_nodeto', get_string('goto','adaquiz').':', $this->getNodesList());
      }
      //$mform->addElement('html', '<div class="fitem submit"><input type="submit" name="addcase" value="'.get_string('addcase', 'adaquiz').'"/></div>');
      $mform->addElement('html', '</div>');
    }

  }

  private function conditionHTMLInput($type, $prefix, $singlejump = null){
    if(!$singlejump){
      $singlejump = Jump::getDefaultJumpCase($type);
    }
    if($type == Jump::TYPE_FINISH_ADAQUIZ){
      return get_string('always', 'adaquiz');
    }else if($type == Jump::TYPE_UNCONDITIONAL){
      return get_string('always', 'adaquiz');
    }else if($type == Jump::TYPE_LASTGRADE){
      $html = get_string('iflastquestiongrade', 'adaquiz') .' ';
      $html .= $this->printSelect($prefix.'_cmp', array('<'=>'<', '>'=>'>'), '', $singlejump->options['cmp']).' ';
      $html .= '<input type="text" name="'.$prefix.'_value" size="4" value="'.$singlejump->options['value'].'"/>';
      $html .= ' / '.$this->node->grade;
    }
    return $html;
  }



  /* * *
   *  FUNCTIONS IN TO HANDLE SUBMITTED DATA
   * * * */
  /**
   * caution: use === to test this funciton.
   * **/
  public function editJumpAction(){
    $edit = optional_param('edit', -1, PARAM_INT);
    return ($edit >= 0)?$edit:false;
  }
  public function addJumpAction(){
   $addtype = optional_param('addtype', '', PARAM_ALPHAEXT);
   return !empty($addtype);
  }
  public function getNodeOptions(){
    $options = array();
    $options['commonrandomseed']     = optional_param('commonrandomseed', false, PARAM_BOOL);
    $options['letstudentdecidejump'] = optional_param('letstudentdecidejump', false, PARAM_BOOL);
    return $options;
  }
  public function updateAction(){
    return optional_param('update', -1, PARAM_INT) >= 0;
  }

  public function getUpdatedCase(){
    $case = new stdClass();
    $case->id       = optional_param('update_id', 0, PARAM_INT);
    $case->type     = optional_param('update_type','',PARAM_TEXT);
    $case->position = optional_param('update_position', -1, PARAM_INT);
    $case->name     = optional_param('update_name','',PARAM_TEXT);
    $case->nodefrom = $this->node->id;
    if($case->type != Jump::TYPE_FINISH_ADAQUIZ){
      $case->nodeto   = optional_param('update_nodeto','',PARAM_INT);
    }else{
      $case->nodeto   = 0;
    }
    $case->options  = array();
    foreach(Jump::getSpecificOptions($case->type) as $name=>$default){
      $case->options[$name] = optional_param('update_'.$name, $default, PARAM_RAW);
    }
    return $case;
  }

  public function getAddCaseOptions(){

    $options = array();
    $addtype = optional_param('addtype', '', PARAM_ALPHAEXT);
    //common options

    $options['type'] = $addtype;
    $options['name'] = optional_param('add'.$addtype.'_name',Jump::getDefaultName($addtype), PARAM_TEXT);
    $options['nodeto'] = optional_param('add'.$addtype.'_nodeto',0, PARAM_INT);
    //type-specific options
    $specific = Jump::getSpecificOptions($addtype);
    foreach($specific as $name=>$default){
      $options[$name] = optional_param('add'.$addtype.'_'.$name, $default, PARAM_RAW);
    }
    return $options;
  }
  public function deleteJumpAction(){
    $delete = optional_param('delete', -1, PARAM_INT);
    return  ($delete >= 0) && $delete<count($this->node->getJump()->singlejumps);
  }
  public function getDeleteJumpPosition(){
    return optional_param('delete', 0, PARAM_INT);
  }
  public function orderAction(){
    return (optional_param('movedown',-1,PARAM_INT)>=0) || (optional_param('moveup',-1,PARAM_INT) >= 0);
  }
  public function getSwitchOrder(){
    if(($down = optional_param('movedown',-1,PARAM_INT))>=0){
      return array($down, $down+1);
    }else if(($up = optional_param('moveup',-1,PARAM_INT))>=0){
      return array($up-1, $up);
    }else{
      return FALSE;
    }
  }

}
