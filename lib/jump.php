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
 * This class represents all jumping possibilities from a given node.
 * In particular, it computes the next node to be accessed during an attempt.
 *
 * Be careful: this class does not represent the same as a record of
 * adaquiz_jump table. This is a set of all records from adaquiz_jump that
 * have common nodefrom.
 *
 * @author Maths for more s.l.
 */
namespace mod_adaquiz\wiris;

require_once($CFG->dirroot.'/lib/dmllib.php');

class Jump {

  const TABLE = 'adaquiz_jump';
  const TYPE_FINISH_ADAQUIZ = 'finish_adaquiz';
  const TYPE_UNCONDITIONAL  = 'unconditional';
  const TYPE_LASTGRADE      = 'last_grade';
  var $nodefrom;  //nodefrom
  var $singlejumps; //array of jumps from nodefrom
  var $updatedSingleJumps;
  //false means no update needed
  //1 means update from user input (no addslashes).
  //2 means update from database (addslashes needed).


  /**
   * This function is the heart of this module.
   * It decides which case applies to given attempt.
   * **/
  public function & getJumpCase(&$attempt){
    foreach($this->singlejumps as $key=>$case){
      if($this->evaluateCondition($case, $attempt)){
        return $case;
      }
    }
    error('Jump is misconfigured!');
  }
  private function evaluateCondition(&$case, &$attempt){
      if($case->type == Jump::TYPE_FINISH_ADAQUIZ || $case->type == Jump::TYPE_UNCONDITIONAL){
        return true;
      }else if($case->type == Jump::TYPE_LASTGRADE){
        $value = (float) $case->options['value'];
        $cmp = $case->options['cmp'];
        $lastgrade = (float) $attempt->getLastQuestionGrade();
        if($cmp == '<'){
          return $lastgrade <  $value;
        }else if($cmp == '>'){
          return $lastgrade > $value;
        }else{//impossible
          return false;
        }
      }else{//impossible
        return false;
      }
  }
  public static function delete($nodefrom){
    global $DB;
    $ok = $DB->delete_records(Jump::TABLE, array('nodefrom' => $nodefrom));
    return $ok;
  }

    /**
     * $this->nodefrom must be set.
     * **/
  public function load(){
    global $DB;
    $this->singlejumps = array();
    $this->updatedSingleJumps = array();
    if(($cases = $DB->get_records(Jump::TABLE, array('nodefrom' => $this->nodefrom), 'position ASC'))!== false){
      foreach($cases as $case){
        $case->options = unserialize($case->options);
        $this->singlejumps[$case->position] = $case;
      }
      foreach($this->singlejumps as $key=>$singlejump){
        $this->updatedSingleJumps[$key] = false;
      }
    }
  }

  public function save(){
    foreach($this->singlejumps as $key=>$singlejump){
      if($this->updatedSingleJumps[$key]!==false){
        $id = Jump::saveSingleJump($singlejump, $this->updatedSingleJumps[$key]==2);
        $this->updatedSingleJumps[$key] = false;
        $this->singlejumps[$key]->id = $id;
      }
    }
  }

  private static function saveSingleJump(&$singlejump, $addslashes = false){
    global $DB;
    $record = $singlejump;
    $record->options = serialize($record->options);
    if($addslashes){
      //$record = addslashes_object($record);
    }
    if(isset($record->id) && $record->id){
      $DB->update_record(Jump::TABLE, $record);
    }else{
      $record->id = $DB->insert_record(Jump::TABLE, $record);
    }
    //AQ-16
    $record->options = unserialize($record->options);
  }


  /**
   * An array of id's of nodes accessible through this jump.
   * **/
  public function getAllDestinations(){
    $dests = array();
    foreach($this->singlejumps as $singlejump){
      $dests[]=$singlejump->nodeto;
    }
    return $dests;
  }

  public function isFinalJump(){
    return (count($this->singlejumps)==1 && $this->singlejumps[0]->type == Jump::TYPE_FINISH_ADAQUIZ);
  }

  /*private function getCaseFromId($jid){
    static $idtopos;
    if(empty($idtopos)){
      foreach($this->singlejumps as $pos=>$case){
        $idtopos[$case->id] = $pos;
      }
    }
    return $this->singlejumps[$idtopos[$jid]];
  }*/

   /**
   * Change all singlejumps with nodeto = $nid to nodeto = 0;
   * @return whether this  jump has been updated (true) or remains
   * unchanged because there wewren't references to $nid (false)
   * **/
  public function removeReferences($nid){
    $this->load();
    $updated = false;
    foreach($this->singlejumps as $key=>$singlejump){
      if($singlejump->nodeto == $nid){
        $this->singlejumps[$key]->nodeto = 0;
        $this->updatedSingleJumps[$key] = 2;
      }
    }
    $this->save();
  }

  public function getConditionString($singlejump){

    if($singlejump->type == Jump::TYPE_FINISH_ADAQUIZ){
      return get_string('always', 'adaquiz');
    }else if($singlejump->type == Jump::TYPE_UNCONDITIONAL){
      return get_string('always', 'adaquiz');
    }else if($singlejump->type == Jump::TYPE_LASTGRADE){
      return get_string('iflastquestiongrade', 'adaquiz') . ' ' . $singlejump->options['cmp'] . ' ' .$singlejump->options['value'];
    }else{
      return false;
    }
  }
  public function addDefaultCase($type, $nodeto = 0){
    $case = Jump::getDefaultJumpCase($type);
    $case->nodefrom = $this->nodefrom;
    $case->nodeto = $nodeto;
    $case->position = count($this->singlejumps);
    $this->singlejumps[$case->position] = $case;
    $this->updatedSingleJumps[$case->position] = 1;
    //$this->save();
  }
  public function addCase($options){
    $case = new \stdClass();
    $case->type     = $options['type'];
    $case->nodefrom = $this->nodefrom;
    $case->nodeto   = $options['nodeto'];
    $case->position = count($this->singlejumps);
    $case->name     = $options['name'];
    $case->options = array();
    foreach(Jump::getSpecificOptions($options['type']) as $name=>$default){
      $case->options[$name] = $options[$name];
    }
    $this->singlejumps[$case->position] = $case;
    $this->updatedSingleJumps[$case->position] = 1;
    $this->save();
  }
  public function deleteSingleJump($position){
    global $DB;
    $singlejump = $this->singlejumps[$position];
    $DB->delete_records(Jump::TABLE, array('id' => $singlejump->id));
    $n = count($this->singlejumps)-1;
    for($i = $position;$i<$n;$i++){
      $this->singlejumps[$i] = $this->singlejumps[$i+1];
      $this->singlejumps[$i]->position = $i;
      $this->updatedSingleJumps[$i] = 2;
    }
    unset($this->singlejumps[$n]);
    unset($this->updatedSingleJumps[$n]);
    $this->save();
  }

  public function switchOrder($pos1, $pos2){
    $sj1 = $this->singlejumps[$pos1];
    $sj2 = $this->singlejumps[$pos2];
    $sj1->position = $pos2;
    $sj2->position = $pos1;
    $this->singlejumps[$pos1] = $sj2;
    $this->singlejumps[$pos2] = $sj1;
    $this->updatedSingleJumps[$pos1] = 2;
    $this->updatedSingleJumps[$pos2] = 2;

    $this->singlejumps[$pos1];
    $this->singlejumps[$pos2];
    $this->save();
  }
  /**
   * It is called for output
   *
   * @return array of all defined name cases for this jump.
   * **/
  public function getCaseNames(){
    $names = array();
    foreach($this->singlejumps as $pos=>$case){
      $names[$pos]=$this->getCaseName($case);
    }
    return $names;
  }

  public function updateCase($case){
    $this->singlejumps[$case->position] = $case;
    $this->updatedSingleJumps[$case->position] = 1;
    $this->save();
  }

  /**
   * @return whether the case in position $position can be deleted. It can be
   * deleted provided that the updated jump has at least one unconditional jump.
   * **/
  public function canDeleteCase($position){
    foreach($this->singlejumps as $pos=>$singlejump){
      if($pos != $position){
        if($singlejump->type == Jump::TYPE_UNCONDITIONAL
        || $singlejump->type == Jump::TYPE_FINISH_ADAQUIZ){
          return true;
        }
      }
    }
    return false;
  }

  public function getCaseName($case){
    if(!isset($case->name)) return '';
    if($this->updatedSingleJumps[$case->position] === 1){
      return stripslashes($case->name);
    }else{
      return $case->name;
    }
  }

  static function getJumpTypes(){
    return array(Jump::TYPE_FINISH_ADAQUIZ, Jump::TYPE_UNCONDITIONAL, Jump::TYPE_LASTGRADE);
  }
    /**
     * @return Jump
     * **/
    static function getJump($nodefrom){
       $j = new Jump();
       $j->nodefrom = $nodefrom;
       $j->load();
       return $j;
    }

    static function createDefaultJump($nodefrom){
      $j = new Jump();
      $j->nodefrom = $nodefrom;
      $j->singlejumps = array();
      $j->singlejumps[0] = Jump::finishAdaquizSingleJump($nodefrom);
      $j->updatedSingleJumps = array();
      $j->updatedSingleJumps[0] = 1;
      return $j;
    }
    /**
     * @return an object representing a single jump case, without nodefrom nor
     * nodeto and with default options.
     * **/
    static function getDefaultJumpCase($type){
      $singlejump = new \StdClass();
      $singlejump->name     = Jump::getDefaultName($type);
      $singlejump->type     = $type;
      $singlejump->position = 0;
      $singlejump->nodefrom = 0;
      $singlejump->nodeto   = 0;
      $singlejump->options  = array();
      foreach(Jump::getSpecificOptions($type) as $name=>$default){
        $singlejump->options[$name]  = $default;
      }
      return $singlejump;
    }
    static function finishAdaquizSingleJump($nodefrom){
      $singlejump = Jump::getDefaultJumpCase(Jump::TYPE_FINISH_ADAQUIZ);
      $singlejump->nodefrom = $nodefrom;
      return $singlejump;
    }

    static function getSpecificOptions($type){
      if($type == Jump::TYPE_LASTGRADE){
        $options = array('cmp'=>'<','value'=>'');
      }else{
        $options = array();
      }
      return $options;
    }

    static function getDefaultName($type){
      if($type == Jump::TYPE_FINISH_ADAQUIZ){
        return get_string('finish', 'adaquiz');
      }else if($type == Jump::TYPE_UNCONDITIONAL){
        return get_string('continue');
      }else if($type == Jump::TYPE_LASTGRADE){
        return '';
      }
    }

}


/**
 * Escape all dangerous characters in a data record
 *
 * $dataobject is an object containing needed data
 * Run over each field exectuting addslashes() function
 * to escape SQL unfriendly characters (e.g. quotes)
 * Handy when writing back data read from the database
 *
 * @param $dataobject Object containing the database record
 * @return object Same object with neccessary characters escaped
 */
function addslashes_object( $dataobject ) {
    $a = get_object_vars( $dataobject);
    foreach ($a as $key=>$value) {
      $a[$key] = addslashes( $value );
    }
    return (object)$a;
}
