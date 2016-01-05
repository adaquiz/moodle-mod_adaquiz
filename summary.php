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
 * This page prints a summary of a quiz attempt before it is submitted.
 *
 * @package   mod_adaquiz
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz\wiris;

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

$attemptid = required_param('attempt', PARAM_INT); // The attempt to summarise.

$PAGE->set_url('/mod/adaquiz/summary.php', array('attempt' => $attemptid));

$attemptobj = Attempt::create($attemptid);

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

// Check that this attempt belongs to this user.
if ($attemptobj->get_userid() != $USER->id) {
    if ($attemptobj->has_capability('mod/adaquiz:viewreports')) {
        redirect($attemptobj->review_url(null));
    } else {
        throw new \moodle_adaquiz_exception($attemptobj->get_adaquizobj(), 'notyourattempt');
    }
}

// Check capabilites.
if (!$attemptobj->is_preview_user()) {
    $attemptobj->require_capability('mod/adaquiz:attempt');
}

if ($attemptobj->is_preview_user()) {
    \navigation_node::override_active_url($attemptobj->start_attempt_url());
}

// Check access.
$accessmanager = $attemptobj->get_access_manager(time());
$accessmanager->setup_attempt_page($PAGE);
$output = $PAGE->get_renderer('mod_adaquiz');
$messages = $accessmanager->prevent_access();
if (!$attemptobj->is_preview_user() && $messages) {
    print_error('attempterror', 'adaquiz', $attemptobj->view_url(),
            $output->access_messages($messages));
}
if ($accessmanager->is_preflight_check_required($attemptobj->get_attemptid())) {
    redirect($attemptobj->start_attempt_url(null));
}

$displayoptions = $attemptobj->get_display_options(false);

// If the attempt is now overdue, or abandoned, deal with that.
// AdaptiveQuiz: TODO.
// $attemptobj->handle_if_time_expired(time(), true);

// If the attempt is already closed, redirect them to the review page.
if ($attemptobj->is_finished()) {
    redirect($attemptobj->review_url());
}

// Arrange for the navigation to be displayed.
if (empty($attemptobj->get_adaquiz()->showblocks)) {
    $PAGE->blocks->show_only_fake_blocks();
}

$navbc = $attemptobj->get_navigation_panel($output, 'adaquiz_attempt_nav_panel', -1);
$regions = $PAGE->blocks->get_regions();
$PAGE->blocks->add_fake_block($navbc, reset($regions));

$PAGE->navbar->add(get_string('summaryofattempt', 'adaquiz'));
$PAGE->set_title($attemptobj->get_adaquiz_name());
$PAGE->set_heading($attemptobj->get_course()->fullname);

// Display the page.
if (empty($attemptobj->get_adaquiz()->showblocks)) {
    $PAGE->blocks->show_only_fake_blocks();
}

// AdaptiveQuiz: Get Adaptive quiz full route.
// TODO: Use get_slot method inside render instead of route.
$route = adaquiz_get_full_route($attemptobj->get_attemptid());

echo $output->summary_page($attemptobj, $displayoptions, $route);

// Log this page view.
$params = array(
    'objectid' => $attemptobj->get_attemptid(),
    'relateduserid' => $attemptobj->get_userid(),
    'courseid' => $attemptobj->get_courseid(),
    'context' => \context_module::instance($attemptobj->get_cmid()),
    'other' => array(
        'adaquizid' => $attemptobj->get_adaquizid()
    )
);
$event = \mod_adaquiz\event\attempt_summary_viewed::create($params);
$event->add_record_snapshot('adaquiz_attempts', $attemptobj->get_attempt());
$event->trigger();
