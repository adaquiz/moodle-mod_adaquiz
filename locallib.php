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
 * Library of functions used by the quiz module.
 *
 * This contains functions that are called from within the adaptivequiz module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/adaquiz/lib.php');
require_once($CFG->dirroot . '/mod/adaquiz/accessmanager.php');
// require_once($CFG->dirroot . '/mod/quiz/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/adaquiz/renderer.php');
require_once($CFG->dirroot . '/mod/adaquiz/attemptlib.php');
require_once($CFG->libdir  . '/eventslib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');


/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the adaptive quiz close date. (1 hour)
 */
define('ADAQUIZ_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the adaptive quiz, then do not take them to the next page of the adaptive quiz. Instead
 * close the quiz immediately.
 */
define('ADAQUIZ_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in adaptive quiz settings.
 */
define('ADAQUIZ_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in adaptive quiz settings.
 */
define('ADAQUIZ_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in adaptive quiz settings.
 */
define('ADAQUIZ_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at an adaptive quiz
 *
 * Creates an attempt object to represent an attempt at the adaptive quiz by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $adaquizobj the adaptive quiz object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param object $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $adaquiz->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int $userid  the id of the user attempting this adaptive quiz.
 *
 * @return object the newly created attempt object.
 */
function adaquiz_create_attempt(adaquiz $adaquizobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $adaquiz = $adaquizobj->get_adaquiz();
    if ($adaquiz->sumgrades < 0.000005 && $adaquiz->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'quiz',
                new moodle_url('/mod/adaquiz/view.php', array('q' => $adaquiz->id)),
                    array('grade' => adaquiz_format_grade($adaquiz, $adaquiz->grade)));
    }

    if ($attemptnumber == 1 || !$adaquiz->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->quiz = $adaquiz->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            print_error('cannotfindprevattempt', 'adaquiz');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->state = adaquiz_attempt::IN_PROGRESS;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $adaquizobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}
/**
 * Start a normal, new, adaptive quiz attempt.
 *
 * @param adaquiz   $adaquizobj         the adaptive quiz object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function adaquiz_start_new_attempt($adaquizobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {
    // Fully load all the questions in this adaptive quiz.
    $adaquizobj->preload_questions();
    $adaquizobj->load_questions();

    // Add them all to the $quba.
    $questionsinuse = array_keys($adaquizobj->get_questions());
    foreach ($adaquizobj->get_questions() as $questiondata) {
        if ($questiondata->qtype != 'random') {
            if (!$adaquizobj->get_adaquiz()->shuffleanswers) {
                $questiondata->options->shuffleanswers = false;
            }
            $question = question_bank::make_question($questiondata);

        } else {
            if (!isset($questionids[$quba->next_slot_number()])) {
                $forcequestionid = null;
            } else {
                $forcequestionid = $questionids[$quba->next_slot_number()];
            }

            $question = question_bank::get_qtype('random')->choose_other_question(
                $questiondata, $questionsinuse, $adaquizobj->get_adaquiz()->shuffleanswers, $forcequestionid);
            if (is_null($question)) {
                throw new moodle_exception('notenoughrandomquestions', 'quiz',
                                           $adaquizobj->view_url(), $questiondata);
            }
        }

        $quba->add_question($question, $questiondata->maxmark);
        $questionsinuse[] = $question->id;
    }

    // Start all the questions.
    if ($attempt->preview) {
        $variantoffset = rand(1, 100);
    } else {
        $variantoffset = $attemptnumber;
    }
    $variantstrategy = new question_variant_pseudorandom_no_repeats_strategy(
            $variantoffset, $attempt->userid, $adaquizobj->get_adaquizid());

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow);

    // Work out the attempt layout.
    $layout = array();
    if ($adaquizobj->get_adaquiz()->shufflequestions) {
        $slots = $quba->get_slots();
        shuffle($slots);

        $questionsonthispage = 0;
        foreach ($slots as $slot) {
            if ($questionsonthispage && $questionsonthispage == $adaquizobj->get_adaquiz()->questionsperpage) {
                $layout[] = 0;
                $questionsonthispage = 0;
            }
            $layout[] = $slot;
            $questionsonthispage += 1;
        }

    } else {
        $currentpage = null;
        foreach ($adaquizobj->get_questions() as $slot) {
            if ($currentpage !== null && $slot->page != $currentpage) {
                $layout[] = 0;
            }
            $layout[] = $slot->slot;
            $currentpage = $slot->page;
        }
    }

    $layout[] = 0;
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param object                        $attempt      this attempt
 * @param object                        $lastattempt  last attempt
 * @return object                       modified attempt object
 *
 */
function adaquiz_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $newslot = $quba->add_question($oldqa->get_question(), $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * The save started question usage and adaptive quiz attempt in db and log the started attempt.
 *
 * @param adaquiz                       $adaquizobj
 * @param question_usage_by_activity $quba
 * @param object                     $attempt
 * @return object                    attempt object with uniqueid and id set.
 */
function adaquiz_attempt_save_started($adaquizobj, $quba, $attempt) {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->id = $DB->insert_record('adaquiz_attempts', $attempt);

    // Params used by the events below.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $adaquizobj->get_courseid(),
        'context' => $adaquizobj->get_context()
    );
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = array(
            'adaquizid' => $adaquizobj->get_adaquizid()
        );
        $event = \mod_adaquiz\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_adaquiz\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('adaquiz', $adaquizobj->get_adaquiz());
    $event->add_record_snapshot('adaquiz_attempts', $attempt);
    $event->trigger();

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given adaptive quiz. This function does not return preview attempts.
 *
 * @param int $adaquizid the id of the adaptive quiz.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function adaquiz_get_user_attempt_unfinished($adaquizid, $userid) {
    $attempts = adaquiz_get_user_attempts($adaquizid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete an adpative quiz attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the adaptive quiz_attempts table).
 * @param object $adaquiz the adaptive quiz object.
 */
function adaquiz_delete_attempt($attempt, $adaquiz) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('adaquiz_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->quiz != $adaquiz->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to adaptive quiz $attempt->quiz " .
                "but was passed adaptive quiz $adaquiz->id.");
        return;
    }

    if (!isset($adaquiz->cmid)) {
        $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id, $adaquiz->course);
        $adaquiz->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('adaquiz_attempts', array('id' => $attempt->id));

    // Log the deletion of the attempt.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'context' => context_module::instance($adaquiz->cmid),
        'other' => array(
            'adaquizid' => $adaquiz->id
        )
    );
    $event = \mod_adaquiz\event\attempt_deleted::create($params);
    $event->add_record_snapshot('adaquiz_attempts', $attempt);
    $event->trigger();

    // Search adaquiz_attempts for other instances by this user.
    // If none, then delete record for this adaptive quiz, this user from adaquiz_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('adaquiz_attempts', array('userid' => $userid, 'quiz' => $adaquiz->id))) {
        $DB->delete_records('adaquiz_grades', array('userid' => $userid, 'quiz' => $adaquiz->id));
    } else {
        adaquiz_save_best_grade($adaquiz, $userid);
    }

    adaquiz_update_grades($adaquiz, $userid);
}

/**
 * Delete all the preview attempts at an adaptive quiz, or possibly all the attempts belonging
 * to one user.
 * @param object $adaquiz the adaptive quiz object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function adaquiz_delete_previews($adaquiz, $userid = null) {
    global $DB;
    $conditions = array('quiz' => $adaquiz->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('adaquiz_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        adaquiz_delete_attempt($attempt, $adaquiz);
    }
}

/**
 * @param int $adaquizid The adaptive quiz id.
 * @return bool whether this adaptive quiz has any (non-preview) attempts.
 */
function adaquiz_has_attempts($adaquizid) {
    global $DB;
    return $DB->record_exists('adaquiz_attempts', array('quiz' => $adaquizid, 'preview' => 0));
}

// Functions to do with adaptive quiz layout and pages //////////////////////////////////

/**
 * Repaginate the questions in an adaptive quiz
 * @param int $adaquizid the id of the adaptive quiz to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function adaquiz_repaginate_questions($adaquizid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $slots = $DB->get_records('adaquiz_slots', array('adaquizid' => $adaquizid),
            'slot');

    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if ($slotsonthispage && $slotsonthispage == $slotsperpage) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('adaquiz_slots', 'page', $currentpage, array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();
}

// Functions to do with adaptive quiz grades ////////////////////////////////////////////

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this adaptive quiz.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $adaquiz the adaptive quiz object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function adaquiz_rescale_grade($rawgrade, $adaquiz, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($adaquiz->sumgrades >= 0.000005) {
        $grade = $rawgrade * $adaquiz->grade / $adaquiz->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = adaquiz_format_question_grade($adaquiz, $grade);
    } else if ($format) {
        $grade = adaquiz_format_grade($adaquiz, $grade);
    }
    return $grade;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this adaptive quiz. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this adaptive quiz.
 * @param object $adaquiz the adaptive quiz settings.
 * @param object $context the adaptive quiz context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function adaquiz_feedback_for_grade($grade, $adaquiz, $context) {
    global $DB;

    if (is_null($grade)) {
        return '';
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('adaquiz_feedback',
            'adaquizid = ? AND mingrade <= ? AND ? < maxgrade', array($adaquiz->id, $grade, $grade));

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_adaquiz', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $adaquiz the adaptive quiz database row.
 * @return bool Whether this adaptive quiz has any non-blank feedback text.
 */
function adaquiz_has_feedback($adaquiz) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($adaquiz->id, $cache)) {
        $cache[$adaquiz->id] = adaquiz_has_grades($adaquiz) &&
                $DB->record_exists_select('adaquiz_feedback', "adaquizid = ? AND " .
                    $DB->sql_isnotempty('adaquiz_feedback', 'feedbacktext', false, true),
                array($adaquiz->id));
    }
    return $cache[$adaquiz->id];
}

/**
 * Update the sumgrades field of the adaptive quiz. This needs to be called whenever
 * the grading structure of the quiz is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link adaquiz_delete_previews()} before you call this function.
 *
 * @param object $adaquiz an adaptive quiz.
 */
function adaquiz_update_sumgrades($adaquiz) {
    global $DB;

    $sql = 'UPDATE {adaquiz}
            SET sumgrades = COALESCE((
                SELECT SUM(maxmark)
                FROM {adaquiz_slots}
                WHERE adaquizid = {adaquiz}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($adaquiz->id));
    $adaquiz->sumgrades = $DB->get_field('adaquiz', 'sumgrades', array('id' => $adaquiz->id));

    if ($adaquiz->sumgrades < 0.000005 && adaquiz_has_attempts($adaquiz->id)) {
        // If the adaptive quiz has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        adaquiz_set_grade(0, $adaquiz);
    }
}

/**
 * Update the sumgrades field of the attempts at an adaptive quiz.
 *
 * @param object $adaquiz an adaptive quiz.
 */
function adaquiz_update_all_attempt_sumgrades($adaquiz) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {adaquiz_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE quiz = :adaquizid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'adaquizid' => $adaquiz->id,
            'finishedstate' => adaquiz_attempt::FINISHED));
}

/**
 * The adaptive quiz grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in adaquiz_grades and adaquiz_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * adaquiz_update_all_attempt_sumgrades, adaquiz_update_all_final_grades and
 * adaquiz_update_grades.
 *
 * @param float $newgrade the new maximum grade for the adpative quiz.
 * @param object $adaquiz the adaptive quiz we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function adaquiz_set_grade($newgrade, $adaquiz) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($adaquiz->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $adaquiz->grade;
    $adaquiz->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the adaptive quiz table.
    $DB->set_field('adaquiz', 'grade', $newgrade, array('id' => $adaquiz->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        adaquiz_update_all_final_grades($adaquiz);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {adaquiz_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE quiz = ?
        ", array($newgrade/$oldgrade, $timemodified, $adaquiz->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the adaptive quiz_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {adaquiz_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE adaquizid = ?
        ", array($factor, $factor, $adaquiz->id));
    }

    // Update grade item and send all grades to gradebook.
    adaquiz_grade_item_update($adaquiz);
    adaquiz_update_grades($adaquiz);

    $transaction->allow_commit();
    return true;
}

/**
 * Save the overall grade for a user at an adaptive quiz in the adaquiz_grades table
 *
 * @param object $adaquiz The adaptive quiz for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function adaquiz_save_best_grade($adaquiz, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = adaquiz_get_user_attempts($adaquiz->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = adaquiz_calculate_best_grade($adaquiz, $attempts);
    $bestgrade = adaquiz_rescale_grade($bestgrade, $adaquiz, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('adaquiz_grades', array('quiz' => $adaquiz->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('adaquiz_grades',
            array('quiz' => $adaquiz->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('adaquiz_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->quiz = $adaquiz->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('adaquiz_grades', $grade);
    }

    adaquiz_update_grades($adaquiz, $userid);
}

/**
 * Calculate the overall grade for an adaptive quiz given a number of attempts by a particular user.
 *
 * @param object $adaquiz    the adaptive quiz settings object.
 * @param array $attempts an array of all the user's attempts at this adaptive quiz in order.
 * @return float          the overall grade
 */
function adaquiz_calculate_best_grade($adaquiz, $attempts) {

    switch ($adaquiz->grademethod) {

        case ADAQUIZ_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case ADAQUIZ_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case ADAQUIZ_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case ADAQUIZ_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this adaptive quiz for all students.
 *
 * This function is equivalent to calling adaquiz_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $adaquiz the adaptive quiz settings.
 */
function adaquiz_update_all_final_grades($adaquiz) {
    global $DB;

    if (!$adaquiz->sumgrades) {
        return;
    }

    $param = array('iquizid' => $adaquiz->id, 'istatefinished' => adaquiz_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                iquiza.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {adaquiz_attempts} iquiza

            WHERE
                iquiza.state = :istatefinished AND
                iquiza.preview = 0 AND
                iquiza.quiz = :iquizid

            GROUP BY iquiza.userid
        ) first_last_attempts ON first_last_attempts.userid = quiza.userid";

    switch ($adaquiz->grademethod) {
        case ADAQUIZ_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quiza.attempt = first_last_attempts.firstattempt AND';
            break;

        case ADAQUIZ_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quiza.attempt = first_last_attempts.lastattempt AND';
            break;

        case ADAQUIZ_GRADEAVERAGE:
            $select = 'AVG(quiza.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case ADAQUIZ_GRADEHIGHEST:
            $select = 'MAX(quiza.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($adaquiz->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($adaquiz->grade / $adaquiz->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['adaquizid'] = $adaquiz->id;
    $param['adaquizid2'] = $adaquiz->id;
    $param['adaquizid3'] = $adaquiz->id;
    $param['adaquizid4'] = $adaquiz->id;
    $param['statefinished'] = adaquiz_attempt::FINISHED;
    $param['statefinished2'] = adaquiz_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT quiza.userid, $finalgrade AS newgrade
            FROM {adaquiz_attempts} quiza
            $join
            WHERE
                $where
                quiza.state = :statefinished AND
                quiza.preview = 0 AND
                quiza.quiz = :adaquizid3
            GROUP BY quiza.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {adaquiz_grades} qg
                WHERE quiz = :adaquizid
            UNION
                SELECT DISTINCT userid
                FROM {adaquiz_attempts} quiza2
                WHERE
                    quiza2.state = :statefinished2 AND
                    quiza2.preview = 0 AND
                    quiza2.quiz = :adaquizid2
            ) users

            LEFT JOIN {adaquiz_grades} qg ON qg.userid = users.userid AND qg.quiz = :adaquizid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->quiz = $adaquiz->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('adaquiz_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('adaquiz_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('adaquiz_grades', 'quiz = ? AND userid ' . $test,
                array_merge(array($adaquiz->id), $params));
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      adaquizid   => (array|int) attempts in given adaptive quiz(s)
 *                      groupid  => (array|int) adaptive quizzes with some override for given group(s)
 *
 */
function adaquiz_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("quiza.state IN ('inprogress', 'overdue')");
    $iwheres = array("iquiza.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz IN (SELECT q.id FROM {adaquiz} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz IN (SELECT q.id FROM {adaquiz} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.userid $incond";
    }

    if (isset($conditions['adaquizid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['adaquizid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['adaquizid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz IN (SELECT qo.quiz FROM {adaquiz_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz IN (SELECT qo.quiz FROM {adaquiz_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $quizausersql = adaquiz_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN quizauser.usertimelimit = 0 AND quizauser.usertimeclose = 0 THEN NULL
               WHEN quizauser.usertimelimit = 0 THEN quizauser.usertimeclose
               WHEN quizauser.usertimeclose = 0 THEN quiza.timestart + quizauser.usertimelimit
               WHEN quiza.timestart + quizauser.usertimelimit < quizauser.usertimeclose THEN quiza.timestart + quizauser.usertimelimit
               ELSE quizauser.usertimeclose END +
          CASE WHEN quiza.state = 'overdue' THEN quiz.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {adaquiz_attempts} quiza
                        JOIN {adaquiz} quiz ON quiz.id = quiza.quiz
                        JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id
                         SET quiza.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {adaquiz_attempts} quiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {adaquiz} quiz, ( $quizausersql ) quizauser
                       WHERE quiz.id = quiza.quiz
                         AND quizauser.id = quiza.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE quiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {adaquiz_attempts} quiza
                        JOIN {adaquiz} quiz ON quiz.id = quiza.quiz
                        JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {adaquiz_attempts} quiza
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {adaquiz} quiz, ( $quizausersql ) quizauser
                            WHERE quiz.id = quiza.quiz
                              AND quizauser.id = quiza.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias iquiza for the adaptive quiz attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function adaquiz_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $quizausersql = "
          SELECT iquiza.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), iquiz.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), iquiz.timelimit) AS usertimelimit

           FROM {adaquiz_attempts} iquiza
           JOIN {adaquiz} iquiz ON iquiz.id = iquiza.quiz
      LEFT JOIN {adaquiz_overrides} quo ON quo.quiz = iquiza.quiz AND quo.userid = iquiza.userid
      LEFT JOIN {groups_members} gm ON gm.userid = iquiza.userid
      LEFT JOIN {adaquiz_overrides} qgo1 ON qgo1.quiz = iquiza.quiz AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {adaquiz_overrides} qgo2 ON qgo2.quiz = iquiza.quiz AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {adaquiz_overrides} qgo3 ON qgo3.quiz = iquiza.quiz AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {adaquiz_overrides} qgo4 ON qgo4.quiz = iquiza.quiz AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY iquiza.id, iquiz.id, iquiz.timeclose, iquiz.timelimit";
    return $quizausersql;
}

/**
 * Return the attempt with the best grade for an adaptive quiz
 *
 * Which attempt is the best depends on $adaquiz->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $adaquiz    The adaptive quiz for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the adaptive quiz
 */
function adaquiz_calculate_best_attempt($adaquiz, $attempts) {

    switch ($adaquiz->grademethod) {

        case ADAQUIZ_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case ADAQUIZ_GRADEAVERAGE: // We need to do something with it.
        case ADAQUIZ_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case ADAQUIZ_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the adaptive quiz grade
 *      from the individual attempt grades.
 */
function adaquiz_get_grading_options() {
    return array(
        ADAQUIZ_GRADEHIGHEST => get_string('gradehighest', 'adaquiz'),
        ADAQUIZ_GRADEAVERAGE => get_string('gradeaverage', 'adaquiz'),
        ADAQUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'adaquiz'),
        ADAQUIZ_ATTEMPTLAST  => get_string('attemptlast', 'adaquiz')
    );
}

/**
 * @param int $option one of the values ADAQUIZ_GRADEHIGHEST, ADAQUIZ_GRADEAVERAGE,
 *      ADAQUIZ_ATTEMPTFIRST or ADAQUIZ_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function adaquiz_get_grading_option_name($option) {
    $strings = adaquiz_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue adaptive quiz
 *      attempts.
 */
function adaquiz_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'adaquiz'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'adaquiz'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'adaquiz'),
    );
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function adaquiz_get_user_image_options() {
    return array(
        ADAQUIZ_SHOWIMAGE_NONE  => get_string('shownoimage', 'adaquiz'),
        ADAQUIZ_SHOWIMAGE_SMALL => get_string('showsmallimage', 'adaquiz'),
        ADAQUIZ_SHOWIMAGE_LARGE => get_string('showlargeimage', 'adaquiz'),
    );
}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function adaquiz_questions_per_page_options() {
    $pageoptions = array();
    $pageoptions[0] = get_string('neverallononepage', 'adaquiz');
    $pageoptions[1] = get_string('everyquestion', 'adaquiz');
    for ($i = 2; $i <= ADAQUIZ_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'adaquiz', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for an adaptive quiz attempt state.
 * @param string $state one of the state constants like {@link adaquiz_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function adaquiz_attempt_state_name($state) {
    switch ($state) {
        case adaquiz_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'adaquiz');
        case adaquiz_attempt::OVERDUE:
            return get_string('stateoverdue', 'adaquiz');
        case adaquiz_attempt::FINISHED:
            return get_string('statefinished', 'adaquiz');
        case adaquiz_attempt::ABANDONED:
            return get_string('stateabandoned', 'adaquiz');
        default:
            throw new coding_exception('Unknown adaptive quiz attempt state.');
    }
}

// Other adaptive quiz functions ////////////////////////////////////////////////////////

/**
 * @param object $adaquiz the adaptive quiz.
 * @param int $cmid the course_module object for this adaptive quiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function adaquiz_question_action_icons($adaquiz, $cmid, $question, $returnurl) {
    $html = adaquiz_question_preview_button($adaquiz, $question) . ' ' .
            adaquiz_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this adaptive quiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function adaquiz_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit', $question->category) ||
                    question_has_capability_on($question, 'move', $question->category))) {
        $action = $stredit;
        $icon = '/t/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view', $question->category)) {
        $action = $strview;
        $icon = '/i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton"><img src="' .
                $OUTPUT->pix_url($icon) . '" alt="' . $action . '" />' . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $adaquiz the adaptive quiz settings
 * @param object $question the question
 * @return moodle_url to preview this question with the options from this adaptive quiz.
 */
function adaquiz_question_preview_url($adaquiz, $question) {
    // Get the appropriate display options.
    $displayoptions = mod_adaquiz_display_options::make_from_adaquiz($adaquiz,
            mod_adaquiz_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return question_preview_url($question->id, $adaquiz->preferredbehaviour,
            $maxmark, $displayoptions);
}

/**
 * @param object $adaquiz the adaptive quiz settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @return the HTML for a preview question icon.
 */
function adaquiz_question_preview_button($adaquiz, $question, $label = false) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use', $question->category)) {
        return '';
    }

    return $PAGE->get_renderer('mod_adaquiz', 'edit')->question_preview_icon($adaquiz, $question, $label);
}

/**
 * @param object $attempt the attempt.
 * @param object $context the adaptive quiz context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function adaquiz_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this adaptive quiz attempt is in - in the sense used by
 * adaquiz_get_review_options, not in the sense of $attempt->state.
 * @param object $adaquiz the adaptive quiz settings
 * @param object $attempt the adaptive quiz_attempt database row.
 * @return int one of the mod_adaquiz_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function adaquiz_attempt_state($adaquiz, $attempt) {
    if ($attempt->state == adaquiz_attempt::IN_PROGRESS) {
        return mod_adaquiz_display_options::DURING;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_adaquiz_display_options::IMMEDIATELY_AFTER;
    } else if (!$adaquiz->timeclose || time() < $adaquiz->timeclose) {
        return mod_adaquiz_display_options::LATER_WHILE_OPEN;
    } else {
        return mod_adaquiz_display_options::AFTER_CLOSE;
    }
}

/**
 * The the appropraite mod_adaquiz_display_options object for this attempt at this
 * adaptive quiz right now.
 *
 * @param object $adaquiz the adaquiz instance.
 * @param object $attempt the attempt in question.
 * @param $context the adaquiz context.
 *
 * @return mod_adaquiz_display_options
 */
function adaquiz_get_review_options($adaquiz, $attempt, $context) {
    $options = mod_adaquiz_display_options::make_from_adaquiz($adaquiz, adaquiz_attempt_state($adaquiz, $attempt));

    $options->readonly = true;
    $options->flags = adaquiz_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/adaquiz/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == adaquiz_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/adaquiz:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/adaquiz/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/adaquiz:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;

    }

    return $options;
}

/**
 * Combines the review options from a number of different adaptive quiz attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = adaquiz_get_combined_reviewoptions(...)
 *
 * @param object $adaquiz the adaptive quiz instance.
 * @param array $attempts an array of attempt objects.
 * @param $context the roles and permissions context,
 *          normally the context for the adaptive quiz module instance.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function adaquiz_get_combined_reviewoptions($adaquiz, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_adaquiz_display_options::make_from_adaquiz($adaquiz,
                adaquiz_attempt_state($adaquiz, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 *
 * @return int|false as for {@link message_send()}.
 */
function adaquiz_send_confirmation($recipient, $a) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_adaquiz';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'adaquiz', $a);
    $eventdata->fullmessage       = get_string('emailconfirmbody', 'adaquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'adaquiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function adaquiz_send_notification($recipient, $submitter, $a) {

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_adaquiz';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'adaquiz', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'adaquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'adaquiz', $a);
    $eventdata->contexturl        = $a->quizreviewurl;
    $eventdata->contexturlname    = $a->quizname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when an adaptive quiz attempt is submitted.
 *
 * @param object $course the course
 * @param object $adaquiz the adaptive quiz
 * @param object $attempt this attempt just finished
 * @param object $context the adaptive quiz context
 * @param object $cm the coursemodule for this adaptive quiz
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function adaquiz_send_notification_messages($course, $adaquiz, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($adaquiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $adaquiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/adaquiz:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang, u.timezone, u.mailformat, u.maildisplay, ';
    $notifyfields .= get_all_user_name_fields(true, 'u');
    $groups = groups_get_all_groups($course->id, $submitter->id);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the adaptive quiz is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/adaquiz:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Adaptive quiz info.
    $a->quizname        = $quiz->name;
    $a->quizreporturl   = $CFG->wwwroot . '/mod/adaquiz/report.php?id=' . $cm->id;
    $a->quizreportlink  = '<a href="' . $a->quizreporturl . '">' .
            format_string($quiz->name) . ' report</a>';
    $a->quizurl         = $CFG->wwwroot . '/mod/adaquiz/view.php?id=' . $cm->id;
    $a->quizlink        = '<a href="' . $a->quizurl . '">' . format_string($adaquiz->name) . '</a>';
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->quizreviewurl   = $CFG->wwwroot . '/mod/quiz/review.php?attempt=' . $attempt->id;
    $a->quizreviewlink  = '<a href="' . $a->quizreviewurl . '">' .
            format_string($adaquiz->name) . ' review</a>';
    // Student who sat the adaptive quiz info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && adaquiz_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && adaquiz_send_confirmation($submitter, $a);
    }

    return $allok;
}

/**
 * Send the notification message when an adaptive quiz attempt becomes overdue.
 *
 * @param adaquiz_attempt $attemptobj all the data about the adaptive quiz attempt.
 */
function adaquiz_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', array('id' => $attemptobj->get_userid()), '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/adaquiz:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $quizname = format_string($attemptobj->get_adaquiz_name());

    $deadlines = array();
    if ($attemptobj->get_adaquiz()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_adaquiz()->timelimit;
    }
    if ($attemptobj->get_adaquiz()->timeclose) {
        $deadlines[] = $attemptobj->get_adaquiz()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_adaquiz()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // Quiz info.
    $a->quizname           = $quizname;
    $a->quizurl            = $attemptobj->view_url();
    $a->quizlink           = '<a href="' . $a->quizurl . '">' . $quizname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $quizname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_adaquiz';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'adaquiz', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'adaquiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'adaquiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the adaquiz_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function adaquiz_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $attempt = $event->get_record_snapshot('adaquiz_attempts', $event->objectid);
    $adaquiz    = $event->get_record_snapshot('adaquiz', $attempt->quiz);
    $cm      = get_coursemodule_from_id('adaquiz', $event->get_context()->instanceid, $event->courseid);

    if (!($course && $adaquiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) && ($adaquiz->completionattemptsexhausted || $adaquiz->completionpass)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return adaquiz_send_notification_messages($course, $adaquiz, $attempt,
            context_module::instance($cm->id), $cm);
}

// Deprecated handlers don't use 

// /**
//  * Handle groups_member_added event
//  *
//  * @param object $event the event object.
//  * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_member_added()}.
//  */
// function adaquiz_groups_member_added_handler($event) {
//     debugging('adaquiz_groups_member_added_handler() is deprecated, please use ' .
//         '\mod_quiz\group_observers::group_member_added() instead.', DEBUG_DEVELOPER);
//     quiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
// }

// /**
//  * Handle groups_member_removed event
//  *
//  * @param object $event the event object.
//  * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_member_removed()}.
//  */
// function quiz_groups_member_removed_handler($event) {
//     debugging('quiz_groups_member_removed_handler() is deprecated, please use ' .
//         '\mod_quiz\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
//     quiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
// }

// /**
//  * Handle groups_group_deleted event
//  *
//  * @param object $event the event object.
//  * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_deleted()}.
//  */
// function quiz_groups_group_deleted_handler($event) {
//     global $DB;
//     debugging('quiz_groups_group_deleted_handler() is deprecated, please use ' .
//         '\mod_quiz\group_observers::group_deleted() instead.', DEBUG_DEVELOPER);
//     quiz_process_group_deleted_in_course($event->courseid);
// }

/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function adaquiz_process_group_deleted_in_course($courseid) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all quizzes with orphaned group overrides.
    $sql = "SELECT o.id, o.quiz
              FROM {adaquiz_overrides} o
              JOIN {adaquiz} quiz ON quiz.id = o.quiz
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE quiz.course = :courseid
               AND o.groupid IS NOT NULL
               AND grp.id IS NULL";
    $params = array('courseid' => $courseid);
    $records = $DB->get_records_sql_menu($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('adaquiz_overrides', 'id', array_keys($records));
    adaquiz_update_open_attempts(array('adaquizid' => array_unique(array_values($records))));
}

// /**
//  * Handle groups_members_removed event
//  *
//  * @param object $event the event object.
//  * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_member_removed()}.
//  */
// function quiz_groups_members_removed_handler($event) {
//     debugging('quiz_groups_members_removed_handler() is deprecated, please use ' .
//         '\mod_quiz\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
//     if ($event->userid == 0) {
//         quiz_update_open_attempts(array('courseid'=>$event->courseid));
//     } else {
//         quiz_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
//     }
// }

/**
 * Get the information about the standard adaptive quiz JavaScript module.
 * @return array a standard jsmodule structure.
 */
function adaquiz_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_adaquiz',
        'fullpath' => '/mod/adaquiz/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine', 'moodle-core-formchangechecker'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'adaquiz'),
            array('startattempt', 'adaquiz'),
            array('timesup', 'adaquiz'),
            array('changesmadereallygoaway', 'moodle'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the adaptive quiz.
 *
 */
class mod_adaquiz_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to an
     * adaptive quiz attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the adaptive quiz settings, and a time constant.
     * @param object $adaquiz the adaptive quiz settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_adaquiz_display_options set up appropriately.
     */
    public static function make_from_adaquiz($adaquiz, $when) {
        $options = new self();

        $options->attempt = self::extract($adaquiz->reviewattempt, $when, true, false);
        $options->correctness = self::extract($adaquiz->reviewcorrectness, $when);
        $options->marks = self::extract($adaquiz->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($adaquiz->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($adaquiz->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($adaquiz->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($adaquiz->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;
        $options->manualcomment = $options->feedback;

        if ($adaquiz->questiondecimalpoints != -1) {
            $options->markdp = $adaquiz->questiondecimalpoints;
        } else {
            $options->markdp = $adaquiz->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}


/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular adaptive quiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_adaquiz extends qubaid_join {
    public function __construct($adaquizid, $includepreviews = true, $onlyfinished = false) {
        $where = 'quiza.quiz = :quizaquiz';
        $params = array('quizaquiz' => $adaquizid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state == :statefinished';
            $params['statefinished'] = adaquiz_attempt::FINISHED;
        }

        parent::__construct('{adaquiz_attempts} quiza', 'quiza.uniqueid', $where, $params);
    }
}

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @return string
 */
function adaquiz_question_tostring($question, $showicon = false, $showquestiontext = true) {
    $result = '';

    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, array('noclean' => true, 'para' => false));
        $questiontext = shorten_text($questiontext, 200);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function adaquiz_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * @param object $adaquiz the adaptive quiz settings.
 * @param int $slot which question in the adaptive quiz to test.
 * @return bool whether the user can use this question.
 */
function adaquiz_has_question_use($adaquiz, $slot) {
    global $DB;
    $question = $DB->get_record_sql("
            SELECT q.*
              FROM {adaquiz_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.adaquizid = ? AND slot.slot = ?", array($adaquiz->id, $slot));
    if (!$question) {
        return false;
    }
    return question_has_capability_on($question, 'use');
}

/**
 * Add a question to an adaptive quiz
 *
 * Adds a question to an adaptive quiz by updating $adaquiz as well as the
 * adaquiz and adaquiz_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $adaquiz The extended adaptive quiz object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in adaptive quiz to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the adaptive quiz
 */
function adaquiz_add_adaquiz_question($questionid, $adaquiz, $page = 0, $maxmark = null) {
    global $DB;
    $slots = $DB->get_records('adaquiz_slots', array('adaquizid' => $adaquiz->id),
            'slot', 'questionid, slot, page, id');
    if (array_key_exists($questionid, $slots)) {
        return false;
    }

    $trans = $DB->start_delegated_transaction();

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new question instance.
    $slot = new stdClass();
    $slot->adaquizid = $adaquiz->id;
    $slot->questionid = $questionid;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('adaquiz_slots', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($adaquiz->questionsperpage && $numonlastpage >= $adaquiz->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $DB->insert_record('adaquiz_slots', $slot);
    $trans->allow_commit();
}

/**
 * Add a random question to the adaptive quiz at a given point.
 * @param object $adaquiz the adaptive quiz settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 */
function adaquiz_add_random_questions($adaquiz, $addonpage, $categoryid, $number,
        $includesubcategories) {
    global $DB;

    $category = $DB->get_record('question_categories', array('id' => $categoryid));
    if (!$category) {
        print_error('invalidcategoryid', 'error');
    }

    $catcontext = context::instance_by_id($category->contextid);
    require_capability('moodle/question:useall', $catcontext);

    // Find existing random questions in this category that are
    // not used by any adaptive quiz.
    if ($existingquestions = $DB->get_records_sql(
            "SELECT q.id, q.qtype FROM {question} q
            WHERE qtype = 'random'
                AND category = ?
                AND " . $DB->sql_compare_text('questiontext') . " = ?
                AND NOT EXISTS (
                        SELECT *
                          FROM {adaquiz_slots}
                         WHERE questionid = q.id)
            ORDER BY id", array($category->id, ($includesubcategories ? '1' : '0')))) {
            // Take as many of these as needed.
        while (($existingquestion = array_shift($existingquestions)) && $number > 0) {
            adaquiz_add_adaquiz_question($existingquestion->id, $adaquiz, $addonpage);
            $number -= 1;
        }
    }

    if ($number <= 0) {
        return;
    }

    // More random questions are needed, create them.
    for ($i = 0; $i < $number; $i += 1) {
        $form = new stdClass();
        $form->questiontext = array('text' => ($includesubcategories ? '1' : '0'), 'format' => 0);
        $form->category = $category->id . ',' . $category->contextid;
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new stdClass();
        $question->qtype = 'random';
        $question = question_bank::get_qtype('random')->save_question($question, $form);
        if (!isset($question->id)) {
            print_error('cannotinsertrandomquestion', 'adaquiz');
        }
        adaquiz_add_adaquiz_question($question->id, $adaquiz, $addonpage);
    }
}
