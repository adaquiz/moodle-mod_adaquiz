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
 * This page handles deleting adaptive quiz overrides
 *
 * @package   mod_adaquiz
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/mod/adaquiz/lib.php');
require_once($CFG->dirroot.'/mod/adaquiz/locallib.php');
require_once($CFG->dirroot.'/mod/adaquiz/override_form.php');

$overrideid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', false, PARAM_BOOL);

if (! $override = $DB->get_record('adaquiz_overrides', array('id' => $overrideid))) {
    print_error('invalidoverrideid', 'adaquiz');
}
if (! $adaquiz = $DB->get_record('adaquiz', array('id' => $override->quiz))) {
    print_error('invalidcoursemodule');
}
if (! $cm = get_coursemodule_from_instance("adaquiz", $adaquiz->id, $adaquiz->course)) {
    print_error('invalidcoursemodule');
}
$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

$context = context_module::instance($cm->id);

require_login($course, false, $cm);

// Check the user has the required capabilities to modify an override.
require_capability('mod/adaquiz:manageoverrides', $context);

$url = new moodle_url('/mod/adaquiz/overridedelete.php', array('id'=>$override->id));
$confirmurl = new moodle_url($url, array('id'=>$override->id, 'confirm'=>1));
$cancelurl = new moodle_url('/mod/adaquiz/overrides.php', array('cmid'=>$cm->id));

if (!empty($override->userid)) {
    $cancelurl->param('mode', 'user');
}

// If confirm is set (PARAM_BOOL) then we have confirmation of intention to delete.
if ($confirm) {
    require_sesskey();

    // Set the course module id before calling adaquiz_delete_override().
    $adaquiz->cmid = $cm->id;
    adaquiz_delete_override($adaquiz, $override->id);

    redirect($cancelurl);
}

// Prepare the page to show the confirmation form.
$stroverride = get_string('override', 'adaquiz');
$title = get_string('deletecheck', null, $stroverride);

$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add($title);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($adaquiz->name, true, array('context' => $context)));

if ($override->groupid) {
    $group = $DB->get_record('groups', array('id' => $override->groupid), 'id, name');
    $confirmstr = get_string("overridedeletegroupsure", "quiz", $group->name);
} else {
    $namefields = get_all_user_name_fields(true);
    $user = $DB->get_record('user', array('id' => $override->userid),
            'id, ' . $namefields);
    $confirmstr = get_string("overridedeleteusersure", "quiz", fullname($user));
}

echo $OUTPUT->confirm($confirmstr, $confirmurl, $cancelurl);

echo $OUTPUT->footer();
