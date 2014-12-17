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

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

global $PAGE;

// Get submitted parameters.
$attemptid = required_param('attempt', PARAM_INT);
$id = required_param('cmid', PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$nextnode = optional_param('node', null, PARAM_INT);
$nav = optional_param('nav', 0, PARAM_BOOL);

if (!$cm = get_coursemodule_from_id('adaquiz', $id)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$data = new stdClass();
$data->id = $cm->instance;
$adaquiz = new Adaquiz($data);

$attempt = $adaquiz->getCurrentAttempt($USER->id);

//Fix for AQ-15
//We only create the adaquiz_attempt if the attempt id is different than the attempt id in the query string
if ($attempt->id == $attemptid) {
	$attemptobj = adaquiz_attempt::create($attemptid);
}
else {
	//Otherwise we delete the created attempt since it is wrong ($uniqueid = -1)
	$attempt->delete();
	print_error("notyourattempt", "adaquiz");
}

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

$actualNodeAttempt = NodeAttempt::getActualNodeAttempt($attempt->id, $page);
$actualNode = $adaquiz->getNode($actualNodeAttempt->node);
$attempt->actualnode = $actualNode;
if ($actualNode->options[Node::OPTION_LETSTUDENTJUMP] == 1){
    $actualNode->getJump();
}

$attempt->attemptobj = $attemptobj;
unset($attemptobj);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);
$PAGE->set_url('/mod/adaquiz/attempt.php', array('attempt' => $attemptid, 'id' => $id, 'page' => $page));

// Check that this attempt belongs to this user.
if ($attempt->attemptobj->get_userid() != $USER->id) {
    if ($attempt->attemptobj->has_capability('mod/adaquiz:viewreports')) {
        redirect($attempt->attemptobj->review_url(null, $page));
    } else {
        throw new moodle_quiz_exception($attempt->attemptobj->get_quizobj(), 'notyourattempt');
    }
}

// Check capabilities and block settings.
if (!$attempt->attemptobj->is_preview_user()) {
    $attempt->attemptobj->require_capability('mod/adaquiz:attempt');
    if (empty($attempt->attemptobj->get_quiz()->showblocks)) {
        $PAGE->blocks->show_only_fake_blocks();
    }

} else {
    navigation_node::override_active_url($attempt->attemptobj->start_attempt_url());
}

// If the attempt is already closed, send them to the review page.
if ($attempt->attemptobj->is_finished()) {
    redirect($attempt->attemptobj->review_url(null, $page));
}

$output = $PAGE->get_renderer('mod_adaquiz');

// Get the question needed by this page.
$slots = $attempt->attemptobj->get_slots($page);
$questionNum = $page+1;

// Check.
if (empty($slots)) {
    throw new moodle_quiz_exception($attempt->attemptobj->get_quizobj(), 'noquestionsfound');
}

// Initialise JavaScript.
$PAGE->requires->js_init_call('M.mod_adaquiz.init_attempt_form', null, false, adaquiz_get_js_module());

// Arrange for the navigation to be displayed in the first region on the page.
$navbc = $attempt->attemptobj->get_navigation_panel($output, 'quiz_attempt_nav_panel', $page);
$regions = $PAGE->blocks->get_regions();
$PAGE->blocks->add_fake_block($navbc, reset($regions));

$PAGE->set_title(format_string($attempt->attemptobj->get_quiz_name()));
$PAGE->set_heading($attempt->attemptobj->get_course()->fullname);

/*
//We don't set the page the get the latest node attempt, where the students should continue the quiz from
$latestNodeAttempt = NodeAttempt::getActualNodeAttempt($attempt->id);
//Show the next button ONLY when is the last node attempt AND the jump has not been set OR is an automatic jump question type!!
if ($attempt->attemptobj->is_last_page($page) || ($attempt->attemptobj->is_last_page($page) && $attempt->attemptobj->is_automatic_jump_question($actualNode->questionobj))) {
    //$nextnode = 0;
}
*/

//Show the next button ONLY when is the last node attempt
if ($attempt->attemptobj->is_last_page($page)) {
	$pagedata = adaquiz_get_last_attempted_page_data($attemptid);
	$nextnode = $pagedata[1];
}

echo $output->attempt_page($attempt, $page, $slots, $attemptid, $nav, $questionNum, $nextnode);