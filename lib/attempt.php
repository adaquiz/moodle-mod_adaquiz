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

namespace mod_adaquiz\wiris;

require_once($CFG->dirroot.'/lib/dmllib.php');
require_once($CFG->dirroot.'/lib/questionlib.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/nodeattempt.php');

class Attempt extends \adaquiz_attempt{

  const TABLE = 'adaquiz_attempts';

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
     * Constructor assuming we already have the necessary data loaded. Overriding
     * adaquiz_attempt class. layout and number_question not loaded.
     *
     * @param object $attempt the row of the adaptive quiz_attempts table.
     * @param object $adaquiz the adaptive quiz object for this attempt and user.
     * @param object $cm the course_module object for this adaptive quiz.
     * @param object $course the row from the course table for the course we belong to.
     * @param bool $loadquestions (optional) if true, the default, load all the details
     *      of the state of each question. Else just set up the basic details of the attempt.
     */
    public function __construct($attempt, $adaquiz, $cm, $course, $loadquestions = true) {
        $this->attempt = $attempt;
        $this->adaquizobj = new adaquiz($adaquiz, $cm, $course);

        if (!$loadquestions) {
            return;
        }

        $this->quba = \question_engine::load_questions_usage_by_activity($this->attempt->uniqueid);
        $this->determine_layout();
        // $this->number_questions();
    }

    /**
     * Used by {create()} and {create_from_usage_id()}.
     * @param array $conditions passed to $DB->get_record('adaquiz_attempts', $conditions).
     */
    protected static function create_helper($conditions) {
        global $DB;

        $attempt = $DB->get_record('adaquiz_attempts', $conditions, '*', MUST_EXIST);
        $adaquiz = \adaquiz_access_manager::load_adaquiz_and_settings($attempt->quiz);
        $course = $DB->get_record('course', array('id' => $adaquiz->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id, $course->id, false, MUST_EXIST);

        // Update adaptive quiz with override information.
        // AdaptiveQuiz there is not override information for the user.
        // $adaquiz = adaquiz_update_effective_access($adaquiz, $attempt->userid);

        return new Attempt($attempt, $adaquiz, $cm, $course);
    }

    /**
     * Static function to create a new adaptive quiz_attempt object given an attemptid.
     *
     * @param int $attemptid the attempt id.
     * @return adaquiz_attempt the new adaptive quiz_attempt object
     */
    public static function create($attemptid) {
        return self::create_helper(array('id' => $attemptid));
    }

    /**
     * Override original method.
     * @return [type] [description]
     */
    protected function determine_layout() {
        global $DB;
        $count = $DB->count_records(NodeAttempt::TABLE, array('attempt' => $this->attempt->id));

        $pagelayout = array();

        for($i = 0; $i < $count; $i++){
            array_push($pagelayout, $i+1);
        }

        $this->pagelayout = $pagelayout;
    }

    /**
     * Return the list of question ids for either a given page of the adaptive quiz, or for the
     * whole adaptive quiz.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the reqested list of question ids.
     */
    public function get_slots($page = 'all') {
        if ($page === 'all') {
            $numbers = array();
            foreach ($this->pagelayout as $numbersonpage) {
                array_push($numbers, $numbersonpage);
            }
            return $numbers;
        } else {
            return array($this->pagelayout[$page]);
        }
    }

    /**
     * New attempt_url method. Is not compatible with original attempt_url method, so we make a
     * new method.
     * @param int $slot if speified, the slot number of a specific question to link to.
     * @param int $page if specified, a particular page to link to. If not givem deduced
     *      from $slot, or goes to the first page.
     * @param int $questionid a question id. If set, will add a fragment to the URL
     * to jump to a particuar question on the page.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return string the URL to continue this attempt.
     */
    public function adaquiz_attempt_url($slot = null, $page = -1, $thispage = -1, $nextnode = null, $rewnode = null) {
        return $this->page_and_question_url('attempt', $slot, $page, false, $thispage, $nextnode, $rewnode);
    }

    /**
     * @return bool whether this attempt has been finished (true) or is still
     *     in progress (false). Be warned that this is not just state == self::FINISHED,
     *     it also includes self::ABANDONED.
     */
    public function is_finished() {
        return $this->attempt->state == Attempt::STATE_FINISHED;
    }

    /**
     * If the attempt is in an applicable state, work out the time by which the
     * student should next do something.
     * @return int timestamp by which the student needs to do something.
     */
    public function get_due_date() {
        $deadlines = array();
        if ($this->adaquizobj->get_adaquiz()->timelimit) {
            $deadlines[] = $this->attempt->timestart + $this->adaquizobj->get_adaquiz()->timelimit;
        }
        if ($this->adaquizobj->get_adaquiz()->timeclose) {
            $deadlines[] = $this->adaquizobj->get_adaquiz()->timeclose;
        }
        if ($deadlines) {
            $duedate = min($deadlines);
        } else {
            return false;
        }

        switch ($this->attempt->state) {
            case self::STATE_ANSWERING:
                return $duedate;

            default:
                throw new coding_exception('Unexpected state: ' . $this->attempt->state);
        }
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
    if(!$this->nodeAttempts && $this->get_adaquizid()){
      $this->nodeAttempts = NodeAttempt::getAllNodeAttempts($this->get_attemptid());
    }
  }

  private function createFirstNodeAttempt(){
    $node = Node::getFirstNode($this->get_adaquizid());
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
  public static function getCurrentAttempt($adaquizobj, $userid, $preview = 0){
    global $DB;
    $record = $DB->get_record_select(Attempt::TABLE, ' quiz = '.$adaquizobj->id.' AND userid = '.$userid.' AND state != '.Attempt::STATE_FINISHED);
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
  public static function getAllAttempts($adaquizobj, $userid = null){
    global $DB;
    $attempts = array();
    $where = 'quiz = '. $adaquizobj->id;
    if(is_number($userid)){
      $where .= ' AND userid = '. $userid;
    }
    if($records = $DB->get_records_select(Attempt::TABLE, $where)){
      foreach($records as $record){
        $attempt = Attempt::create($record->id);
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
    return $DB->count_records(Attempt::TABLE, array('quiz' => $adaquizobj->id, 'preview' => 0));
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

  // Private methods =========================================================

  /**
     * Get a URL for a particular question on a particular page of the quiz.
     * Used by {@link attempt_url()} and {@link review_url()}.
     *
     * @param string $script. Used in the URL like /mod/quiz/$script.php
     * @param int $slot identifies the specific question on the page to jump to.
     *      0 to just use the $page parameter.
     * @param int $page -1 to look up the page number from the slot, otherwise
     *      the page number to go to.
     * @param bool $showall if true, return a URL with showall=1, and not page number
     * @param int $thispage the page we are currently on. Links to questions on this
     *      page will just be a fragment #q123. -1 to disable this.
     * @return The requested URL.
     */
    protected function page_and_question_url($script, $slot, $page, $showall, $thispage, $nextnode = null, $rewnode = null) {
        $url = new \moodle_url('/mod/adaquiz/' . $script . '.php',
                array('attempt' => $this->attempt->id));
        if ($showall) {
            $url->param('showall', 1);
        } else if ($page > 0) {
            $url->param('page', $page);
        }
        if ($this->get_attemptid()){
            $url->param('attempt', $this->get_attemptid());
        }
        if ($this->get_cmid()){
            $url->param('cmid', $this->get_cmid());
        }
        if ($slot){
            $url->param('page', $slot-1);
        }
        if (!is_null($nextnode)){
            $url->param('node', $nextnode);
        }
        if (!is_null($rewnode)){
            $url->param('nav', $rewnode);
        }
        return $url;
    }

}
?>
