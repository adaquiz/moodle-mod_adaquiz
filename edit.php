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
 * Library of functions for the adaptive quiz module.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Page to edit qadaptive uizzes
 *
 * This page generally has two columns:
 * The right column lists all available questions in a chosen category and
 * allows them to be edited or more to be added. This column is only there if
 * the adaptive quiz does not already have student attempts
 * The left column lists all questions that have been added to the current adaptive quiz.
 * The lecturer can add questions from the right hand list to the adaptive quiz or remove them
 *
 * The script also processes a number of actions:
 * Actions affecting a adaptive quiz:
 * up and down  Changes the order of questions and page breaks
 * addquestion  Adds a single question to the adaptive quiz
 * add          Adds several selected questions to the adaptive quiz
 * addrandom    Adds a certain number of random questions to the adaptive quiz
 * repaginate   Re-paginates the adaptive quiz
 * delete       Removes a question from the adaptive quiz
 * savechanges  Saves the order and grades for questions in the adaptive quiz
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaquiz/addrandomform.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/category_class.php');

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$scrollpos = optional_param('scrollpos', '', PARAM_INT);

list($thispageurl, $contexts, $cmid, $cm, $adaquiz, $pagevars) =
        question_edit_setup('editq', '/mod/adaquiz/edit.php', true);

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

$adaquizhasattempts = adaquiz_has_attempts($adaquiz->id);

$PAGE->set_url($thispageurl);

// Get the course object and related bits.
$course = $DB->get_record('course', array('id' => $adaquiz->course), '*', MUST_EXIST);
$adaquizobj = new adaquiz($adaquiz, $cm, $course);
$structure = $adaquizobj->get_structure();

// You need mod/adaquiz:manage in addition to question capabilities to access this page.
require_capability('mod/adaquiz:manage', $contexts->lowest());

// Log this visit.
$params = array(
    'courseid' => $course->id,
    'context' => $contexts->lowest(),
    'other' => array(
        'adaquizid' => $adaquiz->id
    )
);
$event = \mod_adaquiz\event\edit_page_viewed::create($params);
$event->trigger();

// Process commands ============================================================.

// Get the list of question ids had their check-boxes ticked.
$selectedslots = array();
$params = (array) data_submitted();
foreach ($params as $key => $value) {
    if (preg_match('!^s([0-9]+)$!', $key, $matches)) {
        $selectedslots[] = $matches[1];
    }
}

$afteractionurl = new moodle_url($thispageurl);
if ($scrollpos) {
    $afteractionurl->param('scrollpos', $scrollpos);
}

if (optional_param('repaginate', false, PARAM_BOOL) && confirm_sesskey()) {
    // Re-paginate the adaptive quiz.
    $structure->check_can_be_edited();
    $questionsperpage = optional_param('questionsperpage', $adaquiz->questionsperpage, PARAM_INT);
    adaquiz_repaginate_questions($adaquiz->id, $questionsperpage );
    adaquiz_delete_previews($adaquiz);
    redirect($afteractionurl);
}

if (($addquestion = optional_param('addquestion', 0, PARAM_INT)) && confirm_sesskey()) {
    // Add a single question to the current adaptive quiz.
    $structure->check_can_be_edited();
    adaquiz_require_question_use($addquestion);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    adaquiz_add_adaquiz_question($addquestion, $adaquiz, $addonpage);
    adaquiz_delete_previews($adaquiz);
    adaquiz_update_sumgrades($adaquiz);
    $thispageurl->param('lastchanged', $addquestion);
    redirect($afteractionurl);
}

if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    $structure->check_can_be_edited();
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    // Add selected questions to the current adaptive quiz.
    $rawdata = (array) data_submitted();
    foreach ($rawdata as $key => $value) { // Parse input for question ids.
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $key = $matches[1];
            adaquiz_require_question_use($key);
            adaquiz_add_adaquiz_question($key, $adaquiz, $addonpage);
        }
    }
    adaquiz_delete_previews($adaquiz);
    adaquiz_update_sumgrades($adaquiz);
    redirect($afteractionurl);
}

if ((optional_param('addrandom', false, PARAM_BOOL)) && confirm_sesskey()) {
    // Add random questions to the adaptive quiz.
    $structure->check_can_be_edited();
    $recurse = optional_param('recurse', 0, PARAM_BOOL);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    $categoryid = required_param('categoryid', PARAM_INT);
    $randomcount = required_param('randomcount', PARAM_INT);
    adaquiz_add_random_questions($adaquiz, $addonpage, $categoryid, $randomcount, $recurse);

    adaquiz_delete_previews($adaquiz);
    adaquiz_update_sumgrades($adaquiz);
    redirect($afteractionurl);
}

if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {

    // If rescaling is required save the new maximum.
    $maxgrade = unformat_float(optional_param('maxgrade', -1, PARAM_RAW));
    if ($maxgrade >= 0) {
        adaquiz_set_grade($maxgrade, $adaquiz);
        adaquiz_update_all_final_grades($adaquiz);
        adaquiz_update_grades($adaquiz, 0, true);
    }

    redirect($afteractionurl);
}

// Get the question bank view.
$questionbank = new mod_adaquiz\question\bank\custom_view($contexts, $thispageurl, $course, $cm, $adaquiz);
$questionbank->set_adaquiz_has_attempts($adaquizhasattempts);
$questionbank->process_actions($thispageurl, $cm);

// End of process commands =====================================================.

$PAGE->set_pagelayout('incourse');
$PAGE->set_pagetype('mod-adaquiz-edit');

$output = $PAGE->get_renderer('mod_adaquiz', 'edit');

$PAGE->set_title(get_string('editingquizx', 'adaquiz', format_string($adaquiz->name)));
$PAGE->set_heading($course->fullname);
$node = $PAGE->settingsnav->find('mod_adaquiz_edit', navigation_node::TYPE_SETTING);
if ($node) {
    $node->make_active();
}
echo $OUTPUT->header();

// Initialise the JavaScript.
$adaquizeditconfig = new stdClass();
$adaquizeditconfig->url = $thispageurl->out(true, array('qbanktool' => '0'));
$adaquizeditconfig->dialoglisteners = array();
$numberoflisteners = $DB->get_field_sql("
    SELECT COALESCE(MAX(page), 1)
      FROM {adaquiz_slots}
     WHERE adaquizid = ?", array($adaquiz->id));

for ($pageiter = 1; $pageiter <= $numberoflisteners; $pageiter++) {
    $adaquizeditconfig->dialoglisteners[] = 'addrandomdialoglaunch_' . $pageiter;
}

$PAGE->requires->data_for_js('adaquiz_edit_config', $adaquizeditconfig);
$PAGE->requires->js('/question/qengine.js');

// Questions wrapper start.
echo html_writer::start_tag('div', array('class' => 'mod-adaquiz-edit-content'));

echo $output->edit_page($adaquizobj, $structure, $contexts, $thispageurl, $pagevars);

// Questions wrapper end.
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
