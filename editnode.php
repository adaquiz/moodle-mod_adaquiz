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
global $CFG, $DB, $PAGE;
require_once($CFG->dirroot.'/mod/adaquiz/lib/formeditnode.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib/adaquiz.php');
require_once($CFG->dirroot.'/mod/adaquiz/locallib.php');

$nid = required_param('nid', PARAM_INT);
$aq  = required_param('aq', PARAM_INT);

$ada  = new adaptive_quiz($aq);

if (! $course = $DB->get_record('course', array('id' => $ada->course))) {
    error('Course is misconfigured');
}

if(! $cm = get_coursemodule_from_instance('adaquiz', $ada->id, $course->id)){
      error('There is no coursemodule with instance id '.$aq);
}
require_login($course->id, false, $cm);
$context = context_module::instance($cm->id);

$url = new moodle_url('/mod/adaquiz/editnode.php', array('nid' => $nid, 'aq' => $aq));
$PAGE->set_url($url);
$PAGE->set_pagelayout('popup');

$node = $ada->getNode($nid);
$jump = $node->getJump();
$mform = new FormEditNode(null, array('adaquiz'=>$ada, 'node'=>$node));
if($mform->is_cancelled()){
    close_window();
    exit();
}
$savejump = false;


if($mform->is_submitted()){
	$options = $mform->getNodeOptions();
    $node->updateOptions($options);
}

if($mform->orderAction()){
    $switchorder = $mform->getSwitchOrder();
    $jump->switchOrder($switchorder[0], $switchorder[1]);
    $savejump = true;
    redirect($url);
}
if($mform->addJumpAction()){
    $options = $mform->getAddCaseOptions();
    $jump->addCase($options);
    $savejump = true;
    redirect($url);
}
if($mform->deleteJumpAction()){
    $pos = $mform->getDeleteJumpPosition();
	if($jump->canDeleteCase($pos)){
  		$jump->deleteSingleJump($pos);
      	$savejump = true;
        redirect($url);
    }else{
 		$mform->addMessage(get_string('casecantbedeleted', 'adaquiz'));
    }
  }
if($mform->updateAction()){
    $case = $mform->getUpdatedCase();
    $jump->updateCase($case);
    $savejump = true;
    redirect($url);
}


// $mform->explicitDefinition();

$jump->save();

$PAGE->requires->js_init_call('M.mod_adaquiz.init', null, true, adaquiz_get_js_module());

// Start output.
$title = 'Edit jump';
$PAGE->set_title($title);

echo $OUTPUT->header();
$mform->display();

echo $OUTPUT->footer();