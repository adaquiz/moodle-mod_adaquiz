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

$attemptid = required_param('attempt', PARAM_INT); // The attempt to summarise.
$id = required_param('cmid', PARAM_INT); // The attempt to summarise.

$PAGE->set_url('/mod/adaquiz/summary.php', array('attempt' => $attemptid));

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
$attemptobj = adaquiz_attempt::create($attemptid);
$attempt->attemptobj = $attemptobj;
unset($attemptobj);

// Check login.
require_login($attempt->attemptobj->get_course(), false, $attempt->attemptobj->get_cm());

// If this is not our own attempt, display an error.
if ($attempt->attemptobj->get_userid() != $USER->id) {
    print_error('notyourattempt', 'quiz', $attempt->attemptobj->view_url());
}

// Check capabilites.
if (!$attempt->attemptobj->is_preview_user()) {
    $attempt->attemptobj->require_capability('mod/adaquiz:attempt');
}

if ($attempt->attemptobj->is_preview_user()) {
    navigation_node::override_active_url($attempt->attemptobj->start_attempt_url());
}

$output = $PAGE->get_renderer('mod_adaquiz');

$displayoptions = $attempt->attemptobj->get_display_options(false);

// If the attempt is now overdue, or abandoned, deal with that.
$attempt->attemptobj->handle_if_time_expired(time(), true);

// If the attempt is already closed, redirect them to the review page.
if ($attempt->attemptobj->is_finished()) {
    redirect($attempt->attemptobj->review_url());
}

// Arrange for the navigation to be displayed.
if (empty($attempt->attemptobj->get_quiz()->showblocks)) {
    $PAGE->blocks->show_only_fake_blocks();
}

$route = adaquiz_get_full_route($attempt->attemptobj->get_attemptid());
//$route[] = -1;

$navbc = $attempt->attemptobj->get_navigation_panel($output, 'quiz_attempt_nav_panel', -1, false, $route);
//$navbc = $attempt->attemptobj->get_navigation_panel($output, 'quiz_attempt_nav_panel', -1, false);
$regions = $PAGE->blocks->get_regions();
$PAGE->blocks->add_fake_block($navbc, reset($regions));

$PAGE->navbar->add(get_string('summaryofattempt', 'adaquiz'));
$PAGE->set_title(format_string($attempt->attemptobj->get_quiz_name()));
$PAGE->set_heading($attempt->attemptobj->get_course()->fullname);

// Display the page.
//echo $output->summary_page($attempt->attemptobj, $displayoptions, $route);
echo $output->summary_page($attempt->attemptobj, $displayoptions, $route);