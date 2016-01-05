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
 * This script deals with starting a new attempt at an adaptive quiz.
 *
 * Normally, it will end up redirecting to attempt.php - unless a password form is displayed.
 *
 * This code used to be at the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_adaquiz
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz\wiris;

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

// Get submitted parameters.
$id = required_param('cmid', PARAM_INT); // Course module id
$forcenew = optional_param('forcenew', false, PARAM_BOOL); // Used to force a new preview
$page = optional_param('page', -1, PARAM_INT); // Page to jump to in the attempt.

if (!$cm = get_coursemodule_from_id('adaquiz', $id)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$adaquizobj = adaquiz::create($cm->instance, $USER->id);
// This script should only ever be posted to, so set page URL to the view page.
$PAGE->set_url($adaquizobj->view_url());

// Check login and sesskey.
require_login($adaquizobj->get_course(), false, $adaquizobj->get_cm());
require_sesskey();
$PAGE->set_heading($adaquizobj->get_course()->fullname);

// If no questions have been set up yet redirect to edit.php or display an error.
if (!$adaquizobj->has_questions()) {
    if ($adaquizobj->has_capability('mod/adaquiz:manage')) {
        redirect($adaquizobj->edit_url());
    } else {
        print_error('cannotstartnoquestions', 'adaquiz', $adaquizobj->view_url());
    }
}

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = $adaquizobj->get_access_manager($timenow);
if ($adaquizobj->is_preview_user() && $forcenew) {
    $accessmanager->current_attempt_finished();
}

// Check capabilities.
if (!$adaquizobj->is_preview_user()) {
    $adaquizobj->require_capability('mod/adaquiz:attempt');
}

// Check to see if a new preview was requested.
if ($adaquizobj->is_preview_user() && $forcenew) {
    // To force the creation of a new preview, we mark the current attempt (if any)
    // as finished. It will then automatically be deleted below.
    $DB->set_field('adaquiz_attempts', 'state', Attempt::STATE_FINISHED,
            array('quiz' => $adaquizobj->get_adaquizid(), 'userid' => $USER->id));
}

// Look for an existing attempt.
$attempts = adaquiz_get_user_attempts($adaquizobj->get_adaquizid(), $USER->id, 'all', true);
$lastattempt = end($attempts);

// If an in-progress attempt exists, check password then redirect to it.
// AdaptiveQuiz: overriding quiz logic. In-progress state is only STATE_ANSWERING.
if ($lastattempt && $forcenew){
    $lastattempt = null;
}

if ($lastattempt && ($lastattempt->state == Attempt::STATE_ANSWERING)) {
    $currentattemptid = $lastattempt->id;
    $pagedata = adaquiz_get_last_attempted_page_data($currentattemptid);
    $page = $pagedata[0];
    $nextnode = $pagedata[1];

    $messages = $accessmanager->prevent_access();

} else {
    // Get number for the next or unfinished attempt.
    if ($lastattempt && !$lastattempt->preview && !$adaquizobj->is_preview_user()) {
        $attemptnumber = count($attempts);
    } else {
        $lastattempt = false;
        $attemptnumber = 1;
    }
    $currentattemptid = null;
    if ($page == -1) {
        $page = 0;
    }
    $nextnode = null;

    $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

}

if ($currentattemptid) {
    redirect($adaquizobj->attempt_url($currentattemptid, $page, $nextnode));
}
// Check access.
$output = $PAGE->get_renderer('mod_adaquiz');
if (!$adaquizobj->is_preview_user() && $messages) {
    print_error('attempterror', 'adaquiz', $adaquizobj->view_url(),
            $output->access_messages($messages));
}

if ($accessmanager->is_preflight_check_required($currentattemptid)) {
    // Need to do some checks before allowing the user to continue.
    $mform = $accessmanager->get_preflight_check_form(
            $adaquizobj->start_attempt_url($page), $currentattemptid);

    if ($mform->is_cancelled()) {
        $accessmanager->back_to_view_page($output);

    } else if (!$mform->get_data()) {

        // Form not submitted successfully, re-display it and stop.
        $PAGE->set_url($adaquizobj->start_attempt_url($page));
        $PAGE->set_title($adaquizobj->get_adaquiz_name());
        $accessmanager->setup_attempt_page($PAGE);
        if (empty($adaquizobj->get_adaquiz()->showblocks)) {
            $PAGE->blocks->show_only_fake_blocks();
        }

        echo $output->start_attempt_page($adaquizobj, $mform);
        die();
    }

    // Pre-flight check passed.
    $accessmanager->notify_preflight_check_passed($currentattemptid);
}
if ($currentattemptid) {
    // AdaptiveQuiz: OVERDUE attempt statep doesn't exists.
    // if ($lastattempt->state == adaquiz_attempt::OVERDUE) {
    //     redirect($adaquizobj->summary_url($lastattempt->id));
    // } else {
    //     redirect($adaquizobj->attempt_url($currentattemptid, $page));
    // }
    redirect($adaquizobj->attempt_url($currentattemptid, $page, $nextnode));
}

// Delete any previous preview attempts belonging to this user.
adaquiz_delete_previews($adaquizobj->get_adaquiz(), $USER->id);

$quba = \question_engine::make_questions_usage_by_activity('mod_adaquiz', $adaquizobj->get_context());
$quba->set_preferred_behaviour($adaquizobj->get_adaquiz()->preferredbehaviour);

// Create the new attempt and initialize the question sessions
$timenow = time(); // Update time now, in case the server is running really slowly.
$attempt = adaquiz_create_attempt($adaquizobj, $attemptnumber, $lastattempt, $timenow, $adaquizobj->is_preview_user());

$firstnode = $adaquizobj->getFirstNode();
if (!($adaquizobj->get_adaquiz()->attemptonlast && $lastattempt)) {
    $attempt = adaquiz_start_new_attempt($adaquizobj, $quba, $attempt, $attemptnumber, $timenow, array(), array(), $firstnode);
} else {
    $attempt = adaquiz_start_attempt_built_on_last($quba, $attempt, $lastattempt);
}

$transaction = $DB->start_delegated_transaction();

$attempt = adaquiz_attempt_save_started($adaquizobj, $quba, $attempt);

$position= 0;
$na = NodeAttempt::createNodeAttempt($attempt->id, $firstnode->id, $position);
$na->save();

$transaction->allow_commit();
$pagedata = adaquiz_get_last_attempted_page_data($attempt->id);
$nextnode = $pagedata[1];
// Redirect to the attempt page.
redirect($adaquizobj->attempt_url($attempt->id, $page, $nextnode));
