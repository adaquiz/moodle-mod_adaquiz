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
require_once($CFG->libdir.'/pagelib.php');
require_once($CFG->dirroot.'/course/lib.php'); // needed for some blocks
require_once($CFG->dirroot.'/question/editlib.php'); //only when editing. TODO: move this require to a mor specific location.

define('PAGE_ADAQUIZ',   'mod-adaquiz');

page_map_class(PAGE_ADAQUIZ, 'PageAdaquiz');

$DEFINEDPAGES = array(PAGE_ADAQUIZ);

class PageAdaquiz extends page_generic_activity{
  var $adaquiz;
  var $modcontext;
  var $op;
  //Enrico
  var $activityname;

  function init_quick($data) {
    if(empty($data->pageid)) {
      error('Cannot quickly initialize page: empty course id');
    }
    $this->activityname = 'adaquiz';
    parent::init_quick($data);
  }

  function get_type() {
    return PAGE_ADAQUIZ;
  }
    /**
     * This class could load itself the adaquiz, but in this way we prevent
     * loading the object multiple times
     * **/
  function setAdaquiz($adaquiz){
    $this->adaquiz = $adaquiz;
  }
  public function setOperation($op){
    $this->op = $op;
  }

  function getContext(){
    if(empty($this->modcontext)){
      $this->modcontext = context_module::instance($this->modulerecord->id);
    }
    return $this->modcontext;
  }
  /**
   * Print Info, Report, Preview & Edit tabs, depending on the capabilities of the current user.
   * **/
  function printMenu(){
    global $CFG;

    //print thin line in orter to recall the user she is in adaquiz and not in quiz.
    echo '<div class="thingreenline"></div>';

    $context = $this->getContext();
    $tabs = array();
    $row = array();
    if (has_capability('mod/quiz:view', $context)) {
     $row[] = new tabobject('info', $CFG->wwwroot.'/mod/adaquiz/view.php?aq='.$this->adaquiz->id.'&op=info', get_string('info', 'adaquiz'));
    }
    if (has_capability('mod/quiz:viewreports', $context)) {
      $row[] = new tabobject('report', $CFG->wwwroot.'/mod/adaquiz/view.php?aq='.$this->adaquiz->id.'&op=report', get_string('results', 'adaquiz'), get_string('results', 'adaquiz'), true);
    }
    if (has_capability('mod/quiz:preview', $context)) {
      $row[] = new tabobject('attempt', $CFG->wwwroot.'/mod/adaquiz/view.php?aq='.$this->adaquiz->id.'&op=attempt', get_string('preview', 'adaquiz'));
    }
    if (has_capability('mod/quiz:manage', $context)) {
      $row[] = new tabobject('edit', $CFG->wwwroot.'/mod/adaquiz/edit.php?cmid='.$this->modulerecord->id, get_string('edit'));
    }

    $activated = null;
    if(count($row)>1){
      $tabs[] = $row;
      if($this->op == 'review'){
        $currentTab = 'report';
      }else{
        $currentTab = $this->op;
      }
      print_tabs($tabs, $currentTab, null, $activated);
    }

  }

  public function printFooter(){
    print_footer($this->courserecord);
  }

  private function printHiddenElements(){
    echo '<input type="hidden" name="id" value="'.$this->modulerecord->id.'"/>';
    echo '<input type="hidden" name="op" value="'.$this->op.'"/>';
  }

////////////////////////////////////////////////////////////////////////////////
///  INFO
////////////////////////////////////////////////////////////////////////////////

  function printInfo($userid){
    global $CFG;
    $context = $this->getContext();
    // title + intro
    print_heading(get_string('modulename', 'adaquiz').': '.$this->adaquiz->name);
    if(trim(strip_tags($this->adaquiz->intro))) {
      $formatoptions->noclean = true;
      $formatoptions->para    = false;
      print_box(format_text($this->adaquiz->intro, FORMAT_HTML, $formatoptions), 'generalbox', 'intro');
    }
    //table of attempts
    if(has_capability('mod/quiz:viewreports', $context)){
      $numattempts = $this->adaquiz->getNumAttempts();
      if($numattempts){
        echo '<div class="controls">';
        echo '<a href="'.$CFG->wwwroot.'/mod/adaquiz/view.php?&op=report&aq='.$this->adaquiz->id.'">'.
             get_string('attempts', 'adaquiz').': '.$numattempts.'</a>';
        echo '</div>';
      }
    }
    $attempts = Attempt::getAllAttempts($this->adaquiz,$userid);
    $openattempt = false; //whether this user has an open attempt.
    $attempted    = false; //whether this user has an attempt.
    if(!empty($attempts)){
      $attempted = true;
      $table = new stdClass();
      $table->head = array(get_string('attempt', 'adaquiz'), get_string('completed', 'adaquiz'), get_string('grade', 'adaquiz').' / '.$this->adaquiz->grade);
      $table->align = array('center', 'left', 'center');
      $table->data = array();
      $count = 1;
      foreach($attempts as $attempt){
        if($attempt->preview){ //discard previews
          if(!$attempt->isFinished())$openattempt = true;
          continue;
        }
        $row = array();
        if($attempt->isFinished()){
          $date = userdate($attempt->timemodified);
          $op = 'review';
        }else{
          $date = get_string('notyetcompleted', 'adaquiz');
          $op = 'attempt';
          $openattempt = true;
        }
        $row[] = '<a href="'.$CFG->wwwroot.'/mod/adaquiz/view.php?op='.$op.'&aq='.$this->adaquiz->id.'&attempt='.$attempt->id.'" >'.$count.'</a>';
        $row[] = $date;
        $row[] = $attempt->getGrade();
        $table->data[] = $row;
        $count++;
      }
      if(count($table->data)){
        print_table($table);
      }
    }
    //attempt/preview button

    if(has_capability('mod/quiz:preview', $context)){
      if($openattempt){
        $button = get_string('continuepreview','adaquiz');
      }else{
        $button = get_string('preview','adaquiz');
      }
    }else{
      if($openattempt){
        $button = get_string('continueattempt','adaquiz');
      }else if($attempted){
        $button = get_string('reattempt','adaquiz');
      }else{
        $button = get_string('attemptnow','adaquiz');
      }
    }
    echo '<div class="controls">';
    $options = array();
    $options['op']='attempt';
    $options['aq']=$this->adaquiz->id;
    print_single_button($CFG->wwwroot.'/mod/adaquiz/view.php', $options, $button);
    echo '</div>';
  }

////////////////////////////////////////////////////////////////////////////////
///   EDIT
////////////////////////////////////////////////////////////////////////////////

  /**
   * Prints Edit page
   * **/
  var $pageurl;
  var $contexts;
  var $pagevars;
  function printEdit(){
    $_GET['cmid'] = $this->modulerecord->id; //it is used by question_edit_setup in order to find the module.
    list($this->pageurl, $this->contexts, $cmid, $cm, $quiz, $this->pagevars) = question_edit_setup('editq', true);

    echo '<table border="0" style="width:100%" cellpadding="2" cellspacing="0">';
    echo '<tr><td style="width:50%" valign="top">';
    print_box_start('generalbox adaquizquestions');
    print_heading(get_string('nodesinthisadaquiz', 'adaquiz'), '', 2);

    $this->printNodeList();

    print_box_end();
    echo '</td><td style="width:50%" valign="top">';

    $this->printQuestionBank();

    echo '</td></tr>';
    echo '</table>';
  }


  private function printQuestionBank(){

    question_showbank_actions($this->pageurl, $this->modulerecord);

    question_showbank('editq', $this->contexts, $this->pageurl, $this->modulerecord,
      $this->pagevars['qpage'], $this->pagevars['qperpage'], $this->pagevars['qsortorder'],
      $this->pagevars['qsortorderdecoded'], $this->pagevars['cat'],
      $this->pagevars['recurse'], $this->pagevars['showhidden'],
      $this->pagevars['showquestiontext']);
  }

  private function printNodeList(){
    global $USER, $CFG, $COURSE;
    $ada = & $this->adaquiz;
    if ($ada->isEmpty()) {
       echo "<p class=\"quizquestionlistcontrols\">";
       print_string("nonodes", "adaquiz");
       echo "</p>";
    }else if($ada->isAttempted()){
       echo '<p class="quizquestionlistcontrols">';
       print_string('canteditduetoattempts', 'adaquiz');
       echo '</p>';
    }else{
       //form
       echo '<form method="post" action="edit.php">';
       echo '<fieldset class="invisiblefieldset" style="display: block;">';
       echo '<input type="hidden" name="sesskey" value="'.$USER->sesskey.'" />';
       echo $this->pageurl->hidden_params_out();
       //table header
       echo '<table class="nodelist">'."\n";
       echo '<tr><th colspan="2" style="white-space:nowrap;" class="header" scope="col"></th>';
       echo '<th class="header" scope="col"></th>';
       echo '<th align="left" style="white-space:nowrap;" class="header" scope="col">'.get_string('question', 'adaquiz').'</th>';
       echo '<th style="white-space:nowrap;" class="header" scope="col">'.get_string('type', 'adaquiz').'</th>';

       echo '<th style="white-space:nowrap;" class="header" scope="col">'.get_string('jump', 'adaquiz').'</th>';
       echo '<th style="white-space:nowrap;" class="header" scope="col">'.get_string('grade', 'adaquiz').'</th>';
       echo '<th align="center" style="white-space:nowrap;" class="header" scope="col">'.get_string('action', 'adaquiz').'</th>';
       echo '</tr>'."\n";

       foreach($ada->nodes as $position => $node){
         $question = $node->getQuestion();
         $jump = $node->getJump();
         echo '<tr>';
         echo '<td>';
         if ($position != 0) {
           echo '<a title="'.get_string('moveup').'" href="edit.php?cmid='.$this->modulerecord->id.'&moveup='.$node->id.'"><img
                src="'.$CFG->pixpath.'/t/up.gif" class="iconsmall" alt="'.get_string('moveup').'" /></a>';
         }
         echo '</td>';
         echo '<td>';
         if ($position < count($ada->nodes)-1) {
           echo '<a title="'.get_string("movedown").'" href="edit.php?cmid='.$this->modulerecord->id.'&movedown='.$node->id.'"><img
                src="'.$CFG->pixpath.'/t/down.gif" class="iconsmall" alt="'.get_string('movedown').'" /></a>';
         }
         echo '</td>';
         echo '<td>'.($node->position +1).'</td>';

         echo '<td><span title="'.s($question->name).'">'.shorten_text($question->name, 30).'</span></td>';
         echo '<td class="center">';
         print_question_icon($question);
         echo '</td>';
         //JUMP
         echo '<td class="center"><span id="id_jump_string_'.$node->id.'">'.$ada->getJumpString($node->id).'</span></td>';
         //GRADE
         echo '<td class="center"><input type="text" size="2" name="grade_'.$node->position.'" value="'.$node->grade.'"/></td>';
         //ACTIONS
         echo '<td class="center">';

         echo link_to_popup_window('/mod/adaquiz/editnode.php?nid=' .$node->id.'&aq='.$ada->id, 'editnode',
            '<img src="'.$CFG->wwwroot.'/mod/adaquiz/icon.gif" class="iconsmall" alt="'.get_string('editjump', 'adaquiz').'" />',
            0, 0, get_string('editjump', 'adaquiz'), 'width=600,height=450', true);
            echo '&nbsp;';

         if (($question->qtype != 'random') && question_has_capability_on($question, 'use', $question->category)){
            echo link_to_popup_window('/question/preview.php?id=' . $question->id.'&courseid='.$this->courserecord->id, 'questionpreview',
            '<img src="'.$CFG->pixpath.'/t/preview.gif" class="iconsmall" alt="'.get_string('preview').'" />',
            0, 0, get_string('preview'), QUESTION_PREVIEW_POPUP_OPTIONS, true);
            echo '&nbsp;';
            //echo quiz_question_preview_button($quiz, $question);
         }

         $returnurl = $this->pageurl->out();
         $questionparams = array('returnurl' => $returnurl, 'cmid'=>$this->modulerecord->id, 'id' => $question->id);
         $questionurl = new moodle_url($CFG->wwwroot.'/question/question.php', $questionparams);
         if (question_has_capability_on($question, 'edit', $question->category) || question_has_capability_on($question, 'move', $question->category)) {
           echo '<a title="'.get_string('edit').'" href="'.$questionurl->out().'"\>'.
                    '<img src="'.$CFG->pixpath.'/t/edit.gif" class="iconsmall" alt="'.get_string('edit').'" /></a>&nbsp;';
         } else if (question_has_capability_on($question, 'view', $question->category)){
           echo '<a title="'.get_string('view').'" href="'.$questionurl->out(false, array('id'=>$question->id)).'">'.
                 '<img src="'.$CFG->pixpath.'/i/info.gif" alt="'.get_string('view').'" /></a>&nbsp;';
         }
         if (question_has_capability_on($question, 'use', $question->category)) { // remove from quiz, not question delete.
           echo '<a title="'.get_string('remove', 'adaquiz').'" href="'.$this->pageurl->out_action(array('delete'=>$node->id)).'"\>'.
                    '<img src="'.$CFG->pixpath.'/t/removeright.gif" class="iconsmall" alt="'.get_string('remove', 'adaquiz').'" /></a>&nbsp;';
         }
         echo '</td>';
         echo '</tr>';
       }
       //final row with maximum grade
       echo '<tr class="separator"><td class="right" colspan="6">'.get_string('maximumgrade', 'adaquiz').'</td>';
       echo '<td class="center">'.'<input type="text" size="2" name="grade" value="'.$this->adaquiz->grade.'" />'.'</td>';
       echo '</table>';
       echo '<div class="controls"><input type="submit" name="savechanges" value="'.get_string('savechanges', 'adaquiz').'" /></div>';
       echo '</form>';
    }

  }

////////////////////////////////////////////////////////////////////////////////
///   ATTEMPT
////////////////////////////////////////////////////////////////////////////////

  /**
   * @param Attempt $attempt
   * **/
  public function printAttempt(&$attempt){
    if($attempt->isFinished()){
       print_heading(get_string('attemptfinished', 'adaquiz'));
       $this->printReview($attempt);
    }else if($this->adaquiz->isEmpty()){
      print_heading(get_string('nonodes', 'adaquiz'));
    }else{
      $nodeattempt = & $attempt->getCurrentNodeAttempt();
      $question = & $this->adaquiz->getNode($nodeattempt->node)->getQuestion();
      $state = & $attempt->getQuestionState($nodeattempt);
      if($attempt->preview){
        $this->printPreviewTopButton();
      }

      echo '<form id="responseform" method="post" action="'.'view.php'.'" enctype="multipart/form-data" accept-charset="utf-8">'. "\n";
      $this->printHiddenElements();
      if($attempt->preview){

      }
      $number = $nodeattempt->position + 1;
      // Print the question
      print_question($question, $state, $number, $this->adaquiz->getCMOptions(), $this->getRenderOptions($state, false));
      // Print buttons
      $this->printAttemptButtons($attempt);

      echo '</form>'."\n";
    }
  }
  private function printAttemptButtons(&$attempt){
    $nodeattempt = & $attempt->getCurrentNodeAttempt();
    $node = & $this->adaquiz->getNode($nodeattempt->node);
    echo '<div class="controls">'."\n";
    if($attempt->getState() == Attempt::STATE_ANSWERING){
      echo '<input type="submit" name="submit" value="'.get_string('submit').'"/>';
    }
    echo '<div class="jumpcontrols">';
    if($node->options[Node::OPTION_LETSTUDENTJUMP]){//this includes non automatically graded questions.
      echo '<span class="label">'.get_string('jumptoquestion', 'adaquiz').'</span>';
      $jump = $node->getJump();
      $buttons = $jump->getCaseNames();
      foreach($buttons as $pos=>$name){
        echo '<input type="submit" id="next'.$pos.'" name="next" value="'.$name.'"/>';
      }
    }else if($attempt->getState() == Attempt::STATE_REVIEWING){
      echo '<div class="center">';
      echo '<input type="submit" name="next" value="'.get_string('next').'"/>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
  }
  private function printPreviewTopButton(){
    global $CFG;
    $buttonoptions['forcenew'] = 1;
    $buttonoptions['aq'] = $this->adaquiz->id;
    $buttonoptions['op'] = 'attempt';
    echo '<div class="controls">';
    print_single_button($CFG->wwwroot.'/mod/adaquiz/view.php', $buttonoptions, get_string('startagain', 'quiz'));
    echo '</div>';
  }


  /**
   * gets data from submitted form and calls $attempt to perform operations.
   * @param Attempt $attempt
   * @param object data submitted by form
   * **/
  public function processAttemptInput(&$attempt, &$data){
    if($data){
      if(isset($data->submit)){
      //user has submitted a question response.
        $attempt->processQuestionResponse($data);
      }else if(isset($data->next)){
      //user has reviewed a question and wants teh next
        $attempt->createNextNodeAttempt($data);
      }
    }
  }


  /**
   * @see /mod/quiz/locallib.php/quiz_get_renderoptions()
   */
  private function getRenderOptions($state, $reviewquiz = false) {
    $options = new stdClass;
    $opts = &$this->adaquiz->options['review'][$reviewquiz?'adaquiz':'question'];

    $isgraded = question_state_is_graded($state);
    // Show the question in readonly (review) mode if the question is in
    // the closed state
    $options->readonly = $isgraded;

    // Show feedback once the question has been graded (if allowed by the quiz)
    $options->feedback = $isgraded && $opts['feedback'];

    // Show validation only after a validation event
    $options->validation = false;//QUESTION_EVENTVALIDATE === $state->event;

    // Show correct responses in readonly mode if the quiz allows it
    $options->correct_responses = $options->readonly && $opts['correctanswer'];

    // Show general feedback if the question has been graded and the quiz allows it.
    $options->generalfeedback = $isgraded && $opts['feedback'];

    // Show overallfeedback once the attempt is over.
    $options->overallfeedback = $isgraded && $reviewquiz && $opts['feedback'];

    // Always show responses and scores
    $options->responses = $opts['useranswer'];
    $options->scores = $opts['score'];

    //make comment or override grade popup.
    if($isgraded && has_capability('mod/quiz:grade', $this->getContext())){
      $options->questioncommentlink = '/mod/adaquiz/comment.php';
    }
    return $options;
  }

////////////////////////////////////////////////////////////////////////////////
///  REPORT
////////////////////////////////////////////////////////////////////////////////
  private function printAlphaVersion(){
    echo '<div style="text-align: center;"><span class="error">This is a very preliminar version</span></div>'."\n";
  }

  public function printReport(){
    //TODO: make sorteable.
    //$this->printAlphaVersion();
    echo '<form id="responseform" method="post" action="'.'view.php'.'" enctype="multipart/form-data" accept-charset="utf-8">'. "\n";

    $this->printHiddenElements();
    $attempts = $this->adaquiz->getAllAttempts();

    $table = new stdClass();
    $table->head = array(
      '',
      '',
      get_string('user'),
      get_string('completed','adaquiz'),
      get_string('grade').' / '.$this->adaquiz->grade,
      get_string('nodes', 'adaquiz')
    );
    $table->align = array('center','center', 'left', 'left', 'center', 'left');

    $table->data = array();
    $account = null;
    foreach($attempts as $attempt){
      if($attempt->preview) continue;
      if(($account == null) || ($attempt->userid != $account->id)){
        $newuser = true;
        $account = get_record('user','id',$attempt->userid);
      }else{
        $newuser = false;
      }
      $row = array();
      $row[] = '<input type="checkbox" name="attempt_'.$attempt->id.'" value="1">';

      if($newuser){
        $row[] = print_user_picture($account, $this->adaquiz->course, $account->picture, false, true);
        $row[] = fullname($account);
      }else{
        $row[] = '';
        $row[] = '';
      }
      $row[] = '<a href="view.php?op=review&aq='.$this->adaquiz->id.'&attempt='
               .$attempt->id.'" title="'.get_string('review', 'adaquiz').'" >'.userdate($attempt->timemodified)
               .'</a>';
      if($attempt->id == $this->adaquiz->getGradedAttempt($account->id)->id){
        $row[] = '<div class="highlight">'.$attempt->getGrade().'</div>';
      }else{
        $row[] = $attempt->getGrade();
      }


      $row[] = $attempt->getNodePositionsList();
      $table->data[] = $row;
    }
    if(count($table->data)==0){
      print_heading(get_string('noattempts', 'adaquiz'));
    }else{
      echo '<div class="center">'.get_string('gradedappempthighlighted', 'adaquiz').'</div>';

      echo '<div id="tablecontainer">';
      print_table($table);
      echo '</div>';
      if(has_capability('mod/quiz:deleteattempts', $this->getContext())){
        echo '<div class="tablecontrols">';
        echo '<a href="javascript:select_all_in(\'DIV\',null,\'tablecontainer\');">'.
        get_string('selectall').'</a> / ';
        echo '<a href="javascript:deselect_all_in(\'DIV\',null,\'tablecontainer\');">'.
        get_string('deselectall').'</a> ';
        echo '&nbsp;&nbsp;';
        echo '<input type="submit" name="deleteselected" value="'.get_string('deleteselected').'"/>';
        echo '</div>';
      }
      echo '</form>';
    }
  }

  function processReportInput(&$data){
    if(isset($data->deleteselected) && !empty($data->deleteselected)){
      $changed = false;
      $attempts = $this->adaquiz->getAllAttempts();
      $attids = array();
      foreach($attempts as $attempt){
        $attids[intval($attempt->id)] = $attempt;
      }

      foreach($data as $name=>$value){
        if(substr($name,0,strlen('attempt'))=='attempt'){
          $id = intval(substr($name, strlen('attempt_')));
          if(isset($attids[$id])){
            $attids[$id]->delete();
            $changed = true;
          }
        }
      }
      if($changed) $this->adaquiz->attempts = null;
    }
  }

////////////////////////////////////////////////////////////////////////////////
/// REVIEW
////////////////////////////////////////////////////////////////////////////////

  function printReview(&$attempt){
    global $CFG;
    $isteacher = has_capability('mod/quiz:preview', $this->modcontext);
    if (!has_capability('mod/quiz:viewreports', $this->modcontext)) {
      global $USER;
      if ($attempt->userid != $USER->id) {
        error("This is not your attempt!", 'view.php?aq=' . $this->adaquiz->id);
      }
    }
    if($attempt->preview){
      print_heading($this->adaquiz->name.': '.get_string('reviewofpreview', 'adaquiz'));
      $this->printPreviewTopButton();
    }else{
      //compute attempt number:
      $attempts = $attempt->getAllAttempts($this->adaquiz, $attempt->userid);
      $count = 1; $num = 1;
      foreach($attempts as $att){
        if($att->id == $attempt->id){
          $num = $count;
          break;
        }
        $count++;
      }
      print_heading($this->adaquiz->name.': '.get_string('reviewofattempt', 'adaquiz'). ' '. $num) ;
    }

    if(!$isteacher){
      echo '<div class="controls">';
      print_single_button($CFG->wwwroot.'/mod/adaquiz/view.php', array( 'aq' => $this->adaquiz->id ), get_string('finishreview', 'adaquiz'));
      echo '</div>';
    }


    echo '<form id="responseform" method="post" action="'.'view.php'.'" enctype="multipart/form-data" accept-charset="utf-8">'. "\n";
    $this->printHiddenElements();
    $nodeattempts = $attempt->getNodeAttempts();
    $this->printAdaquizReviewDetails($attempt);


    foreach($nodeattempts as $nodeattempt){
      //get question & state
      $question = & $this->adaquiz->getNode($nodeattempt->node)->getQuestion();
      $state    = & $attempt->getQuestionState($nodeattempt);// Print the question

      //print question
      print_question($question, $state, $nodeattempt->position+1, $this->adaquiz->getCMOptions(), $this->getRenderOptions($state, true));
      //print jump
      $this->printJump($nodeattempt);
    }
  }


private function printAdaquizReviewDetails(&$attempt){
  global $CFG;
  //Print summary table about the whole attempt.
  $table = array();
  //1- student:
  $student = $account = get_record('user','id',$attempt->userid);
  $picture = print_user_picture($student, $this->adaquiz->course, $student->picture, false, true);

  $table[] = array($picture, '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$attempt->userid.'&course='.$this->adaquiz->course.'" >'.fullname($student).'</a>');
  //2 attempts
  $attempts = $attempt->getAllAttempts($this->adaquiz, $attempt->userid);
  $atts = '';
  $count = 1;
  foreach($attempts as $att){
     if(!empty($atts)) $atts .= ', ';
     if($att->id == $attempt->id){
       $atts .= $count;
     }else{
       $atts .= '<a href="'.$CFG->wwwroot.'/mod/adaquiz/view.php?aq='.$this->adaquiz->id;
       $atts .= '&op=review&attempt='.$att->id.'" title="'.get_string('review', 'adaquiz').'" >';
       $atts .= $count. '</a>';
     }
     $count++;
  }
  $table[] = array(get_string('attempts', 'adaquiz'), $atts);
  //time created, ended, time taken
  $table[] = array(get_string('timestarted','adaquiz'), userdate($attempt->timecreated));
  if(! $attempt->isFinished()){
    $table[] = array(get_string('timefinished','adaquiz'), get_string('unfinished', 'adaquiz'));
  }else{
    $table[] = array(get_string('timefinished','adaquiz'), userdate($attempt->timemodified));
    $table[] = array(get_string('timetaken','adaquiz'), format_time($attempt->timemodified - $attempt->timecreated));
  }
  //nodes
  $natts = $attempt->getNodePositionsList();
  $table[] = array(get_string('nodes', 'adaquiz'), $natts);
  //grade

  $grade =  '<strong>'.$attempt->getGrade() . '</strong> ' . get_string('outofamaximumof', 'adaquiz') . ' <strong>' . $this->adaquiz->grade. '</strong>';
  $grade .= ' (<strong>'. round(($attempt->getGrade()/($this->adaquiz->grade!=0?$this->adaquiz->grade:1))*100, 0) .'%</strong>)';
  $table[] = array(get_string('grade', 'adaquiz'), $grade);

  //print table
  echo '<table border="0" class="generaltable generalbox adaquizreviewsummary">';
  foreach($table as $row){
    echo '<tr>';
    echo '<th class="cell">'.$row[0].'</th>';
    echo '<td class="cell">'.$row[1].'</td>';
    echo '</tr>';
  }
  echo '</table>';


}
private function printJump(&$nodeattempt){
  $jumpId = $nodeattempt->jump;
  $jump = & $this->adaquiz->getNode($nodeattempt->node)->getJump();
  $case = null;
  foreach($jump->singlejumps as $pos=>$singlejump){
    if($singlejump->id == $jumpId){
      $case = & $singlejump;
    }
  }
  echo '<div class="generalbox jump">';
  echo '<span class="label">'.get_string('jump', 'adaquiz').' '.($case->position+1).': </span>';
  echo ' <span class="jumpname">'.$case->name . '</span> ';
  if($case->nodeto){
    echo get_string('tonode', 'adaquiz').' '. ($this->adaquiz->getNode($case->nodeto)->position +1);
  }
  echo '</div>';
}

}
////////////////////////////////////////////////////////////////////////////////
/// END OF CLASS
////////////////////////////////////////////////////////////////////////////////

/**
* Callback function called from question_list() function (which is called from showbank())
* Displays action icon as first action for each question.
*/
function module_specific_actions($pageurl, $questionid, $cmid, $canuse){
  global $CFG;
  if ($canuse){
    $movearrow = 'moveleft.gif';
    $straddtoquiz = get_string("addtoadaquiz", "adaquiz");
    $out = "<a title=\"$straddtoquiz\" href=\"edit.php?".$pageurl->get_query_string()."&amp;addquestion=$questionid&amp;sesskey=".sesskey()."\"><img
          src=\"$CFG->pixpath/t/$movearrow\" alt=\"$straddtoquiz\" /></a>&nbsp;";
    return $out;
  } else {
    return '';
  }
}

/**
 * Callback function called from question_list() function (which is called from showbank())
 */
function module_specific_controls($totalnumber, $recurse, $category, $cmid){
  global $QTYPES;
  $out = '';
  $catcontext = get_context_instance_by_id($category->contextid);
  if (has_capability('moodle/question:useall', $catcontext)){
    $randomusablequestions = $QTYPES['random']->get_usable_questions_from_category($category->id, $recurse, '0');
    $maxrand = count($randomusablequestions);
    if ($maxrand > 0) {
      $out .= '<br />';
      $out .= '<input type="hidden" name="categoryid" value="'.$category->id.'" />';
      $out .= '<input type="hidden" name="recurse" value="'.$recurse.'" />';
      $out .= '<input type="submit" name="addrandom" value="'. get_string('addrandom', 'adaquiz') .'" />';
      $out .= helpbutton('random', get_string('random', 'adaquiz'), 'quiz', true, false, '', true);
    }
  }
  return $out;
}
