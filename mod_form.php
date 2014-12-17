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

require_once ($CFG->dirroot.'/course/moodleform_mod.php');


class mod_adaquiz_mod_form extends moodleform_mod {
  /**
   * Define add/update form, extending the default add/update module form.
   * **/
  function definition() {
     global $COURSE, $CFG;
     $mform    =& $this->_form;

     //Name and introduction
     $mform->addElement('header', 'general', get_string('general', 'form'));

     $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
     if (!empty($CFG->formatstringstriptags)) {
       $mform->setType('name', PARAM_TEXT);
     } else {
       $mform->setType('name', PARAM_CLEAN);
     }
     $mform->addRule('name', null, 'required', null, 'client');
     $this->add_intro_editor(false, get_string('introduction', 'adaquiz'));
     $mform->addHelpButton('introeditor', 'helprichtext', 'adaquiz');
     
     //Question behaviour
     $mform->addElement('header', 'interactionhdr', get_string('questionbehaviour', 'quiz'));

     $behaviours = question_engine::get_behaviour_options(ADAQUIZ_QUESTIONBEHAVIOUR);
     //Leave only two behaviours: immediatefeedback and interactive
     $notsupportedbehaviours = array('adaptive', 'adaptivenopenalty', 'deferredfeedback', 'deferredcbm', 'immediatecbm');
     foreach($notsupportedbehaviours as $key) {
     	unset($behaviours[$key]);
     }
     $mform->addElement('select', 'preferredbehaviour',
     get_string('howquestionsbehave', 'question'), $behaviours);
     $mform->addHelpButton('preferredbehaviour', 'howquestionsbehave', 'question');
     $mform->setDefault('preferredbehaviour', ADAQUIZ_QUESTIONBEHAVIOUR);
     
     //Review options
     $mform->addElement('header', 'reviewoptionshdr', get_string('review', 'adaquiz'));
     $afterquestion = array();
     $afterquestion[] = &$mform->createElement('advcheckbox', 'afterquestionuseranswer', '', get_string('useranswer','adaquiz'), null, array(0,1));
     $afterquestion[] = &$mform->createElement('advcheckbox', 'afterquestioncorrectanswer', '', get_string('correctanswer','adaquiz'), null, array(0,1));
     $afterquestion[] = &$mform->createElement('advcheckbox', 'afterquestionfeedback', '', get_string('feedback','adaquiz'), null, array(0,1));
     $afterquestion[] = &$mform->createElement('advcheckbox', 'afterquestionscore', '', get_string('score', 'adaquiz'), null, array(0,1));
     $mform->addGroup($afterquestion, 'afterquestion', get_string('reviewafterquestion','adaquiz'), array(' '), false);
     $mform->setDefault('afterquestionuseranswer',ADAQUIZ_REVIEWAFTERQUESTION_USERANSWER);
     $mform->setDefault('afterquestioncorrectanswer',ADAQUIZ_REVIEWAFTERQUESTION_CORRECTANSWER);
     $mform->setDefault('afterquestionfeedback',ADAQUIZ_REVIEWAFTERQUESTION_FEEDBACK);
     $mform->setDefault('afterquestionscore',ADAQUIZ_REVIEWAFTERQUESTION_SCORE);

     $afterquiz = array();
     $afterquiz[] = &$mform->createElement('advcheckbox', 'afterquizuseranswer', '', get_string('useranswer','adaquiz'), null, array(0,1));
     $afterquiz[] = &$mform->createElement('advcheckbox', 'afterquizcorrectanswer', '', get_string('correctanswer','adaquiz'), null, array(0,1));
     $afterquiz[] = &$mform->createElement('advcheckbox', 'afterquizfeedback', '', get_string('feedback','adaquiz'), null, array(0,1));
     $afterquiz[] = &$mform->createElement('advcheckbox', 'afterquizscore', '', get_string('score','adaquiz'), null, array(0,1));
     $mform->addGroup($afterquiz, 'afterquiz', get_string('reviewafterquiz','adaquiz'), array(' '), false);
     $mform->setDefault('afterquizuseranswer',ADAQUIZ_REVIEWAFTERQUIZ_USERANSWER);
     $mform->setDefault('afterquizcorrectanswer',ADAQUIZ_REVIEWAFTERQUIZ_CORRECTANSWER);
     $mform->setDefault('afterquizfeedback',ADAQUIZ_REVIEWAFTERQUIZ_FEEDBACK);
     $mform->setDefault('afterquizscore',ADAQUIZ_REVIEWAFTERQUIZ_SCORE);

  //Common module settings

     $features = new stdClass;
     $features->groups = false;
     $this->standard_coursemodule_elements($features);

     $this->add_action_buttons();
  }
  /**
   * Set default values.
   * **/
  function data_preprocessing(&$default_values){
      if(isset($default_values['options'])){
        $options = unserialize($default_values['options']);

        $qr = &$options['review']['question'];
        $default_values['afterquestionuseranswer']=$qr['useranswer'];
        $default_values['afterquestioncorrectanswer']=$qr['correctanswer'];
        $default_values['afterquestionfeedback']=$qr['feedback'];
        $default_values['afterquestionscore']=$qr['score'];

        $ar = &$options['review']['adaquiz'];
        $default_values['afterquizuseranswer']=$ar['useranswer'];
        $default_values['afterquizcorrectanswer']=$ar['correctanswer'];
        $default_values['afterquizfeedback']=$ar['feedback'];
        $default_values['afterquizscore']=$ar['score'];
        
        $qb = &$options['preferredbehaviour'];
        $default_values['preferredbehaviour'] = $qb;
      }
      
  }
  /**
   * Validate form
   * **/
  function validation($data, $files) {
    $errors = parent::validation($data, $files);
    //Validate custom form elements
    if (count($errors) == 0) {
      return true;
    } else {
      return $errors;
    }
  }
}

?>