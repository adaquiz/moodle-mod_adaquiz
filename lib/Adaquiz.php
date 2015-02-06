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
 * Adaquiz. Main class of adaquiz module. It represents the whole quiz. Its the
 * entry point for external interfaces such as ../lib.php.
 *
 * @author Maths for more s.l.
 */
require_once($CFG->dirroot.'/lib/dmllib.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/Node.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/Attempt.php');

//default configuration constants
define('ADAQUIZ_REVIEWAFTERQUESTION_USERANSWER', true);
define('ADAQUIZ_REVIEWAFTERQUESTION_CORRECTANSWER', false);
define('ADAQUIZ_REVIEWAFTERQUESTION_FEEDBACK', true);
define('ADAQUIZ_REVIEWAFTERQUESTION_SCORE', true);
define('ADAQUIZ_REVIEWAFTERQUIZ_USERANSWER', true);
define('ADAQUIZ_REVIEWAFTERQUIZ_CORRECTANSWER', true);
define('ADAQUIZ_REVIEWAFTERQUIZ_FEEDBACK', true);
define('ADAQUIZ_REVIEWAFTERQUIZ_SCORE', true);

define('ADAQUIZ_QUESTIONBEHAVIOUR', 'immediatefeedback');


class Adaquiz {

    const TABLE = 'adaquiz';

    //basic values saved in adaquiz table.
    var $id;
    var $course;
    var $name;
    var $intro;
    var $timecreated;
    var $timemodified;
    var $grade;
    var $options;//array


    //other useful structures from other tables.
    var $nodes;

    //calculated data
    var $attempts;

    /**
     * @param mixed $data. An object containing some  of the attributes of this
     *        class. It usually comes from a form submit. If $data->id is not null
     *        then the object is loaded from database and overwritten with $data.
     *        If $data is an integer, it is supposed to be the id.
     * **/
    public function Adaquiz($data = stdClass){
      if(is_numeric($data)){
        $id = $data;
        $data = new stdClass();
        $data->id = $id;
      }
      if(isset($data->id)){
        $this->id = $data->id;
        if(!$this->load()){
          return false;
        }
      }else{
        $this->timecreated = time();
        $this->loadAttributes($data);
        $this->loadFlatOptions($data);
      }

      //set lazy loaded items to null:
      $this->nodes = null;
    }

    /**
     *
     *
     *
     * **/
    public function getNodesFromQuestions($qids){
        global $DB, $CFG;
        $qidsstr = implode(',', $qids);
        $SQL = 'SELECT id FROM '.$CFG->prefix.Node::TABLE.' WHERE question IN ('.$qidsstr.') AND adaquiz = ' . $this->id;
        $records = $DB->get_records_sql($SQL);
        return $records;
    }

    /**
     * Load object data from the database, using $this->id.
     * **/
    private function load(){
      global $DB;
      if(!$record = $DB->get_record(Adaquiz::TABLE, array('id' => $this->id))){
        return false;
      }
      $this->loadAttributes($record);
      $this->loadOptions($record);
      return true;
    }
    /**
     * Sets this class properties from object $record.
     * @param object $record
     * **/
    private function loadAttributes($record){
      if(isset($record->course)){
        $this->course = $record->course;
      }
      if(isset($record->name)){
        $this->name = $record->name;
      }
      if(isset($record->intro)){
        $this->intro = $record->intro;
      }
      if(isset($record->timecreated)){
        $this->timecreated = $record->timecreated;
      }
      if(isset($record->timemodified)){
        $this->timemodified = $record->timemodified;
      }
      if(isset($record->grade)){
        $this->grade = $record->grade;
      }else{
        $this->grade = 10; //default
      }
    }

    /**
     * Loads options property from the serialized $record->options
     * @param object $record.
     * **/
    private function loadOptions($record){
      if($record->options){
        $this->options = unserialize($record->options);
      }else{
        $this->options = array();
      }
    }

    /**
     * loads options from a flat structure comming from the module edit form.
     * **/
    private function loadFlatOptions($record){
      if(empty($this->options)){
        $this->options = array();
        $this->options['review'] = array();
        $this->options['review']['question'] = array();
        $this->options['review']['adaquiz'] = array();
        $this->options['preferredbehaviour'] = "";
      }
      $qr = &$this->options['review']['question'];
      if(isset($record->afterquestionuseranswer))
        $qr['useranswer'] = $record->afterquestionuseranswer;
      if(isset($record->afterquestioncorrectanswer))
        $qr['correctanswer'] = $record->afterquestioncorrectanswer;
      if(isset($record->afterquestionfeedback))
        $qr['feedback'] = $record->afterquestionfeedback;
      if(isset($record->afterquestionscore))
        $qr['score'] = $record->afterquestionscore;

      $ar = &$this->options['review']['adaquiz'];
      if(isset($record->afterquizuseranswer))
        $ar['useranswer'] = $record->afterquizuseranswer;
      if(isset($record->afterquizcorrectanswer))
        $ar['correctanswer'] = $record->afterquizcorrectanswer;
      if(isset($record->afterquizfeedback))
        $ar['feedback'] = $record->afterquizfeedback;
      if(isset($record->afterquizscore))
        $ar['score'] = $record->afterquizscore;

      //question behaviour
      $qb = &$this->options['preferredbehaviour'];
      if(isset($record->preferredbehaviour))
        $qb = $record->preferredbehaviour;
    }


    /**
     * Saves this object to database. Do not save its nodes.
     * **/
    public function save(){
      global $DB;
      $this->timemodified = time();
      $optionsArray = $this->options;
      $this->options = serialize($optionsArray);
      if($this->id){
        $DB->update_record(Adaquiz::TABLE, $this);
      }else{
        $this->id = $DB->insert_record(Adaquiz::TABLE, $this);
      }
      $this->options = $optionsArray;
      //update gradeItem
      $this->gradeItemUpdate();
      return $this->id;
    }

    /**
     * Loads nodes from database.
     * **/
    private function loadAllNodes(){
      if($this->nodes == null){
        if($this->id){
          $this->nodes = Node::getAllNodes($this->id);
        }else{
          $this->nodes = array();
        }
      }
    }

    /**
     * @return Node the first node.
     * **/
    public function &getFirstNode(){
      if(!is_null($this->nodes)){
        if(isset($this->nodes[0])){
          return $this->nodes[0];
        }else{
          return false;
        }
      }else{
        return Node::getFirstNode($this->id);
      }
    }
    public function &getNode($nid){
      $this->loadAllNodes();
      foreach($this->nodes as $node){
        if($node->id == $nid){
          return $node;
        }
      }
      $null = null;
      return $null;
    }
    public function getJumpString($nid){
      $positions = $this->getDestinationPositions($nid);
      $str = '';
      foreach($positions as $pos){
        if($str!='')$str .= ', ';
        if($pos == -1) $str .= get_string('end', 'adaquiz');
        else $str .= $pos+1;
      }
      return $str;
    }
    public function getDestinationPositions($nid){
      $node = $this->getNode($nid);
      $jump = $node->getjump();
      $dests = $jump->getAllDestinations();
      $positions = array();
      foreach($dests as $dest){
        if($dest == 0){
          $positions[] = -1;
        }else{
          $positions[]=$this->getNode($dest)->position;
        }
      }
      return $positions;
    }

    public function moveUp($nid){
      $node = $this->getNode($nid);
      $node2 = $this->nodes[$node->position -1];
      $this->switchNodes($node, $node2);
    }
    public function moveDown($nid){
      $node = $this->getNode($nid);
      $node2 = $this->nodes[$node->position +1];
      $this->switchNodes($node, $node2);
    }
    /**
     * @param n1 Node
     * @param n2 Node
     * **/
    private function switchNodes(&$n1, &$n2){
      $aux = $n1->position;
      $n1->position = $n2->position;
      $n2->position = $aux;
      $this->nodes[$n1->position] = $n1;
      $this->nodes[$n2->position] = $n2;
      $n1->save();
      $n2->save();
    }
    /**
     * This function is static because it is not needed to load the full
     * object in order just to delet it.
     * **/
    public function delete(){
      global $DB;
      $this->gradeItemDelete();
      $ok = Node::deleteAllNodes($this->id);
      $ok = $DB->delete_records(Adaquiz::TABLE, array('id' => $this->id)) && $ok;
      return $ok;
    }

    /**
     * @return whether this object has no nodes.
     * **/
    public function isEmpty(){
      $this->loadAllNodes();
      return empty($this->nodes);
    }

    /**
     * @return whether this node has already been attempted by some user.
     * Previews doesn't count.
     *      * **/
    public function isAttempted(){
      return (Attempt::getNumAttempts($this) > 0);
    }

    /**
     * Adds a question to this adaquiz. It creates a default configured node with
     * the given question.
     * @param integer $qid the question id.
     * **/
    function addQuestion($qid) {
      $this->loadAllNodes();

      //Check the same question has not been already inserted in the Adaquiz
      if ($this->checkNodePresent($qid)){
          return false;
      }

      //Create Node
      $n = Node::createDefaultNode();
      $n->adaquiz = $this->id;
      $n->position = count($this->nodes);
      $n->question = $qid;
      $n->grade = $n->getQuestion()->defaultmark;
      $n->save();
      $this->nodes[$n->position] = $n;

      //Assign a default Jump to this node
      $j = Jump::createDefaultJump($n->id);
      $j->save();

      $n->jump = $j;

      //change the previous node jump if it was the end jump.
      if(count($this->nodes)>1){
        $previous = $this->nodes[$n->position -1];
        $pjump = $previous->getJump();
        if($pjump->isFinalJump()){
          $pjump->deleteSingleJump(0);
          $pjump->addDefaultCase(Jump::TYPE_UNCONDITIONAL, $n->id);
          $pjump->save();
        }
      }

    }

    /**
     * Check if a particular node has been already inserted in the adaquiz
     * @param int qid question id
     * **/
    private function checkNodePresent($qid){
        foreach($this->nodes as $key => $value){
            if ($value->question == $qid){
                return true;
            }
        }
        return false;
    }

    /**
     * Adds a question of type 'random' to this adaquiz.
     * @param object category the category of the random question.
     * **/
    function addRandomQuestion(&$category, $recurse){
      //Get unused random question
      $question = $this->getUnusedRandomQuestion($category, $recurse);
      //Create new random question
      if(!$question){
        $question = $this->createRandomQuestion($category, $recurse);
      }
      //Add question to this adaquiz
      $this->addQuestion($question->id);
    }

    private function getUnusedRandomQuestion(&$category, $recurse){
      global $CFG;
      $sql = 'SELECT * FROM '.$CFG->prefix.'question q ';
      $sql .='WHERE q.qtype = \''.RANDOM.'\' AND q.category = '.$category->id.' ';
      $sql .='AND ' . sql_compare_text('q.questiontext') . ' = \''.($recurse?'1':'0').'\' '; //recurse = true
      $sql .='AND NOT EXISTS (SELECT n.id FROM '.$CFG->prefix.Node::TABLE.' n WHERE n.question = q.id) ';
      $sql .='ORDER BY q.id';
      $question = get_record_sql($sql, true);
      return $question;
    }

    private function createRandomQuestion(&$category, $recurse){
      global $QTYPES;

      $form = new stdClass();
      $form->questiontext = $recurse?'1':'0'; // we use the questiontext field to store the info
      $form->questiontextformat = 0;
      $form->image = '';
      $form->defaultgrade = 1;
      $form->hidden = 1;
      $form->category = $category->id.','.$category->contextid;
      $form->stamp = make_unique_id_code();  // Set the unique code (not to be changed)

      $question = new stdClass;
      $question->qtype = RANDOM;

      $courseobj = get_record('course', 'id', $this->course);
      $question = $QTYPES[RANDOM]->save_question($question, $form, $courseobj);

      if(!isset($question->id)) {
        error('Could not insert new random question!');
      }

      return $question;
    }
    /**
     * Deletes selected node
     * @param $nid integer node id.
     * **/
    function deleteNode($nid){
      $this->loadAllNodes();
      $deleted = null;
      foreach($this->nodes as $key=>$node){
        if($node->id == $nid){
          unset($this->nodes[$key]);
          $node->delete();
          $deleted = $node;
          break;
        }
      }
      if($deleted != null){
        foreach($this->nodes as $key=>$node){
          if($node->position > $deleted->position){
            $this->nodes[$key]->position--;
            $node->save();
          }
          $node->getJump()->removeReferences($deleted->id);
        }
      }
      return true;
    }
    /**
     * @return Attempt. Last attempt or a new one.
     * **/
    public function getCurrentAttempt($uid, $preview = 0){
      return Attempt::getCurrentAttempt($this, $uid, $preview);
    }
    /**
     * @return integer The total number of attempts (not previews) for this adaquiz.
     * **/
    public function getNumAttempts(){
      return Attempt::getNumAttempts($this);
    }
    public function getCMOptions(){
      $cmoptions = new stdClass();
      $cmoptions->id = $this->id;
      $cmoptions->timelimit = 0;
      $cmoptions->penaltyscheme = 0;
      $cmoptions->id = 0;
      $cmoptions->optionflags = 0;
      $cmoptions->course = $this->course;
      $cmoptions->decimalpoints = 1;
      $cmoptions->shuffleanswers = 1;
      $cmoptions->timeclose = 0;
      $questions = array();
      foreach($this->getNodes() as $node){
        $questions[] = $node->question;
      }
      $cmoptions->questionsinuse = implode(',', $questions);
      return $cmoptions;
    }

    public function deletePreviews(){
      $attempts = $this->getAllAttempts();
      $previews = array();
      foreach($attempts as $attempt){
        if($attempt->preview == 1){
          $previews[] = $attempt;
        }
      }
      for($i=count($previews)-1; $i>=0;$i--){
        $previews[$i]->delete();
      }
    }

    public function getAllAttempts($userid = null){
      if($userid!=null){
        return Attempt::getAllAttempts($this, $userid);
      }else{
        if(!is_array($this->attempts)){
          $this->attempts = Attempt::getAllAttempts($this);
          Attempt::sortAttemptsByUserName($this->attempts);
        }
        return $this->attempts;
      }
    }

    public function getNodes(){
      $this->loadAllNodes();
      return $this->nodes;
    }

    private function highestGradedAttempt(&$userattempts){
      $gradedattempt = false;
      $maxgrade = -1;
      foreach($userattempts as $attempt){
        if($attempt->getGrade()>$maxgrade){
          $gradedattempt = $attempt;
          $maxgrade = $attempt->getGrade();
        }
      }
      return $gradedattempt;
    }
    /**
     * @return the attempt used to grade this activity. returns false if no such
     * attempt exists.
     */
    public function getGradedAttempt($userid){
      $userattempts = $this->getAllAttempts($userid);
      return $this->highestGradedAttempt($userattempts);
    }
    /**
     * @return array of all graded attempts
     * **/
    public function getGradedAttempts(){
      $attempts = $this->getAllAttempts();
      $byuser = array();
      foreach($attempts as $attempt){
        if(!isset($byuser[$attempt->userid])){
          $byuser[$attempt->userid] = array();
        }
        $byuser[$attempt->userid][] = $attempt;
      }
      $graded = array();
      foreach($byuser as $userid=>$userattempts){
        $graded[$userid] = $this->highestGradedAttempt($userattempts);
      }
      return $graded;
    }

    public function getUserGrades($userid=0){
      if($userid){
        $attempt = $this->getGradedAttempt($userid);
        if(!$attempt) return false;
        $attempts = array($attempt);
      }else{
        $attempts = $this->getGradedAttempts();
        if(empty($attempts)) return false;
      }

      $grades = array();
      foreach($attempts as $attempt){
        $grade = new stdClass;
        $grade->userid        = $attempt->userid;
        $grade->rawgrade      = $attempt->getGrade();
        $grade->dategraded    = $attempt->timemodified;
        $grade->datesubmitted = $attempt->timemodified;
        $grades[$grade->userid] = $grade;
      }
      return $grades;
    }
/**
 * Update grades in central gradebook
 * @param int $userid specific user only, 0 mean all
 */
    public function updateGrades($userid=0, $nullifnone=true){
      global $CFG;
      if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
      }
      if ($grades = $this->getUserGrades($userid)) {
        $this->gradeItemUpdate($grades);
      } else if ($userid && $nullifnone) {
        $grade = new object();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        $this->gradeItemUpdate($grade);
      } else {
        $this->gradeItemUpdate();
      }
    }

    private function gradeItemDelete(){
      global $CFG;
      if(!function_exists('grade_update')){
        require_once($CFG->libdir.'/gradelib.php');
      }
      return grade_update('mod/adaquiz', $this->course, 'mod', 'adaquiz', $this->id, 0, NULL, array('deleted'=>1));
    }
/**
 * Create grade item for this quiz
 * @param mixed optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
    public function gradeItemUpdate($grades=null){
      global $CFG;
      if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
      }
      $params = array();
      $params['itemname'] = $this->name;

      if ($this->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $this->grade;
        $params['grademin']  = 0;
      }else{
        $params['gradetype'] = GRADE_TYPE_NONE;
      }
      //maybe $params['hidden'] should be set here.
      //here it can be checked whether the user wants to update grade when the
      //grade is locked.
      return grade_update('mod/adaquiz', $this->course, 'mod', 'adaquiz', $this->id, 0, $grades, $params);
    }

    public function getParticipants(){
      $users = array();
      $attempts = $this->getAllAttempts();
      foreach($attempts as $attempt){
        $users[$attempt->userid] = new stdClass();
        $users[$attempt->userid]->id = $attempt->userid;
      }
      return $users;
    }
}
