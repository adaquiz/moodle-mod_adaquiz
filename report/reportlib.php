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
 * Helper functions for the adaptive quiz reports.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/adaquiz/lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Takes an array of objects and constructs a multidimensional array keyed by
 * the keys it finds on the object.
 * @param array $datum an array of objects with properties on the object
 * including the keys passed as the next param.
 * @param array $keys Array of strings with the names of the properties on the
 * objects in datum that you want to index the multidimensional array by.
 * @param bool $keysunique If there is not only one object for each
 * combination of keys you are using you should set $keysunique to true.
 * Otherwise all the object will be added to a zero based array. So the array
 * returned will have count($keys) + 1 indexs.
 * @return array multidimensional array properly indexed.
 */
function adaquiz_report_index_by_keys($datum, $keys, $keysunique = true) {
    if (!$datum) {
        return array();
    }
    $key = array_shift($keys);
    $datumkeyed = array();
    foreach ($datum as $data) {
        if ($keys || !$keysunique) {
            $datumkeyed[$data->{$key}][]= $data;
        } else {
            $datumkeyed[$data->{$key}]= $data;
        }
    }
    if ($keys) {
        foreach ($datumkeyed as $datakey => $datakeyed) {
            $datumkeyed[$datakey] = adaquiz_report_index_by_keys($datakeyed, $keys, $keysunique);
        }
    }
    return $datumkeyed;
}

function adaquiz_report_unindex($datum) {
    if (!$datum) {
        return $datum;
    }
    $datumunkeyed = array();
    foreach ($datum as $value) {
        if (is_array($value)) {
            $datumunkeyed = array_merge($datumunkeyed, adaquiz_report_unindex($value));
        } else {
            $datumunkeyed[] = $value;
        }
    }
    return $datumunkeyed;
}

/**
 * Are there any questions in this adaquiz?
 * @param int $adaquizid the adaptive quiz id.
 */
function adaquiz_has_questions($adaquizid) {
    global $DB;
    return $DB->record_exists('adaquiz_slots', array('adaquizid' => $adaquizid));
}

/**
 * Get the slots of real questions (not descriptions) in this adaptive quiz, in order.
 * @param object $adaquiz the adaptive quiz.
 * @return array of slot => $question object with fields
 *      ->slot, ->id, ->maxmark, ->number, ->length.
 */
function adaquiz_report_get_significant_questions($adaquiz) {
    global $DB;

    $qsbyslot = $DB->get_records_sql("
            SELECT slot.slot,
                   q.id,
                   q.length,
                   slot.maxmark

              FROM {question} q
              JOIN {adaquiz_slots} slot ON slot.questionid = q.id

             WHERE slot.adaquizid = ?
               AND q.length > 0

          ORDER BY slot.slot", array($adaquiz->id));

    $number = 1;
    foreach ($qsbyslot as $question) {
        $question->number = $number;
        $number += $question->length;
    }

    return $qsbyslot;
}

/**
 * @param object $adaquiz the adaptive quiz settings.
 * @return bool whether, for this adaptive quiz, it is possible to filter attempts to show
 *      only those that gave the final grade.
 */
function adaquiz_report_can_filter_only_graded($adaquiz) {
    return $adaquiz->attempts != 1 && $adaquiz->grademethod != ADAQUIZ_GRADEAVERAGE;
}

/**
 * This is a wrapper for {@link adaquiz_report_grade_method_sql} that takes the whole adaptive quiz object instead of just the grading method
 * as a param. See definition for {@link adaquiz_report_grade_method_sql} below.
 *
 * @param object $adaquiz
 * @param string $adaquizattemptsalias sql alias for 'adaquiz_attempts' table
 * @return string sql to test if this is an attempt that will contribute towards the grade of the user
 */
function adaquiz_report_qm_filter_select($adaquiz, $adaquizattemptsalias = 'quiza') {
    if ($adaquiz->attempts == 1) {
        // This adaptive quiz only allows one attempt.
        return '';
    }
    return adaquiz_report_grade_method_sql($adaquiz->grademethod, $adaquizattemptsalias);
}

/**
 * Given an adaptive quiz grading method return sql to test if this is an
 * attempt that will be contribute towards the grade of the user. Or return an
 * empty string if the grading method is ADAQUIZ_GRADEAVERAGE and thus all attempts
 * contribute to final grade.
 *
 * @param string $grademethod adaptive quiz grading method.
 * @param string $adaquizattemptsalias sql alias for 'adaquiz_attempts' table
 * @return string sql to test if this is an attempt that will contribute towards the graded of the user
 */
function adaquiz_report_grade_method_sql($grademethod, $adaquizattemptsalias = 'quiza') {
    switch ($grademethod) {
        case ADAQUIZ_GRADEHIGHEST :
            return "($adaquizattemptsalias.state = 'finished' AND NOT EXISTS (
                           SELECT 1 FROM {adaquiz_attempts} qa2
                            WHERE qa2.quiz = $adaquizattemptsalias.quiz AND
                                qa2.userid = $adaquizattemptsalias.userid AND
                                 qa2.state = 'finished' AND (
                COALESCE(qa2.sumgrades, 0) > COALESCE($adaquizattemptsalias.sumgrades, 0) OR
               (COALESCE(qa2.sumgrades, 0) = COALESCE($adaquizattemptsalias.sumgrades, 0) AND qa2.attempt < $adaquizattemptsalias.attempt)
                                )))";

        case ADAQUIZ_GRADEAVERAGE :
            return '';

        case ADAQUIZ_ATTEMPTFIRST :
            return "($adaquizattemptsalias.state = 'finished' AND NOT EXISTS (
                           SELECT 1 FROM {adaquiz_attempts} qa2
                            WHERE qa2.quiz = $adaquizattemptsalias.quiz AND
                                qa2.userid = $adaquizattemptsalias.userid AND
                                 qa2.state = 'finished' AND
                               qa2.attempt < $adaquizattemptsalias.attempt))";

        case ADAQUIZ_ATTEMPTLAST :
            return "($adaquizattemptsalias.state = 'finished' AND NOT EXISTS (
                           SELECT 1 FROM {adaquiz_attempts} qa2
                            WHERE qa2.quiz = $adaquizattemptsalias.quiz AND
                                qa2.userid = $adaquizattemptsalias.userid AND
                                 qa2.state = 'finished' AND
                               qa2.attempt > $adaquizattemptsalias.attempt))";
    }
}

/**
 * Get the number of students whose score was in a particular band for this adaptive quiz.
 * @param number $bandwidth the width of each band.
 * @param int $bands the number of bands
 * @param int $adaquizid the adaptive quiz id.
 * @param array $userids list of user ids.
 * @return array band number => number of users with scores in that band.
 */
function adaquiz_report_grade_bands($bandwidth, $bands, $adaquizid, $userids = array()) {
    global $DB;
    if (!is_int($bands)) {
        debugging('$bands passed to adaquiz_report_grade_bands must be an integer. (' .
                gettype($bands) . ' passed.)', DEBUG_DEVELOPER);
        $bands = (int) $bands;
    }

    if ($userids) {
        list($usql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $usql = "qg.userid $usql AND";
    } else {
        $usql = '';
        $params = array();
    }
    $sql = "
SELECT band, COUNT(1)

FROM (
    SELECT FLOOR(qg.grade / :bandwidth) AS band
      FROM {adaquiz_grades} qg
     WHERE $usql qg.quiz = :adaquizid
) subquery

GROUP BY
    band

ORDER BY
    band";

    $params['adaquizid'] = $adaquizid;
    $params['bandwidth'] = $bandwidth;

    $data = $DB->get_records_sql_menu($sql, $params);

    // We need to create array elements with values 0 at indexes where there is no element.
    $data = $data + array_fill(0, $bands + 1, 0);
    ksort($data);

    // Place the maximum (perfect grade) into the last band i.e. make last
    // band for example 9 <= g <=10 (where 10 is the perfect grade) rather than
    // just 9 <= g <10.
    $data[$bands - 1] += $data[$bands];
    unset($data[$bands]);

    return $data;
}

function adaquiz_report_highlighting_grading_method($adaquiz, $qmsubselect, $qmfilter) {
    if ($adaquiz->attempts == 1) {
        return '<p>' . get_string('onlyoneattemptallowed', 'adaquiz_overview') . '</p>';

    } else if (!$qmsubselect) {
        return '<p>' . get_string('allattemptscontributetograde', 'adaquiz_overview') . '</p>';

    } else if ($qmfilter) {
        return '<p>' . get_string('showinggraded', 'adaquiz_overview') . '</p>';

    } else {
        return '<p>' . get_string('showinggradedandungraded', 'adaquiz_overview',
                '<span class="gradedattempt">' . adaquiz_get_grading_option_name($adaquiz->grademethod) .
                '</span>') . '</p>';
    }
}

/**
 * Get the feedback text for a grade on this adaptive quiz. The feedback is
 * processed ready for display.
 *
 * @param float $grade a grade on this adaptive quiz.
 * @param int $adaquizid the id of the adaptive quiz object.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function adaquiz_report_feedback_for_grade($grade, $adaquizid, $context) {
    global $DB;

    static $feedbackcache = array();

    if (!isset($feedbackcache[$adaquizid])) {
        $feedbackcache[$adaquizid] = $DB->get_records('adaquiz_feedback', array('adaquizid' => $adaquizid));
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedbacks = $feedbackcache[$adaquizid];
    $feedbackid = 0;
    $feedbacktext = '';
    $feedbacktextformat = FORMAT_MOODLE;
    foreach ($feedbacks as $feedback) {
        if ($feedback->mingrade <= $grade && $grade < $feedback->maxgrade) {
            $feedbackid = $feedback->id;
            $feedbacktext = $feedback->feedbacktext;
            $feedbacktextformat = $feedback->feedbacktextformat;
            break;
        }
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedbacktext, 'pluginfile.php',
            $context->id, 'mod_adaquiz', 'feedback', $feedbackid);
    $feedbacktext = format_text($feedbacktext, $feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * Format a number as a percentage out of $adaquiz->sumgrades
 * @param number $rawgrade the mark to format.
 * @param object $adaquiz the adaptive quiz settings
 * @param bool $round whether to round the results ot $adaquiz->decimalpoints.
 */
function adaquiz_report_scale_summarks_as_percentage($rawmark, $adaquiz, $round = true) {
    if ($adaquiz->sumgrades == 0) {
        return '';
    }
    if (!is_numeric($rawmark)) {
        return $rawmark;
    }

    $mark = $rawmark * 100 / $adaquiz->sumgrades;
    if ($round) {
        $mark = adaquiz_format_grade($adaquiz, $mark);
    }
    return $mark . '%';
}

/**
 * Returns an array of reports to which the current user has access to.
 * @return array reports are ordered as they should be for display in tabs.
 */
function adaquiz_report_list($context) {
    global $DB;
    static $reportlist = null;
    if (!is_null($reportlist)) {
        return $reportlist;
    }

    $reports = $DB->get_records('adaquiz_reports', null, 'displayorder DESC', 'name, capability');
    $reportdirs = core_component::get_plugin_list('adaquiz');

    // Order the reports tab in descending order of displayorder.
    $reportcaps = array();
    foreach ($reports as $key => $report) {
        if (array_key_exists($report->name, $reportdirs)) {
            $reportcaps[$report->name] = $report->capability;
        }
    }

    // Add any other reports, which are on disc but not in the DB, on the end.
    foreach ($reportdirs as $reportname => $notused) {
        if (!isset($reportcaps[$reportname])) {
            $reportcaps[$reportname] = null;
        }
    }
    $reportlist = array();
    foreach ($reportcaps as $name => $capability) {
        if (empty($capability)) {
            $capability = 'mod/adaquiz:viewreports';
        }
        if (has_capability($capability, $context)) {
            $reportlist[] = $name;
        }
    }
    return $reportlist;
}

/**
 * Create a filename for use when downloading data from an adaptive quiz report. It is
 * expected that this will be passed to flexible_table::is_downloading, which
 * cleans the filename of bad characters and adds the file extension.
 * @param string $report the type of report.
 * @param string $courseshortname the course shortname.
 * @param string $adaquizname the adaptive quiz name.
 * @return string the filename.
 */
function adaquiz_report_download_filename($report, $courseshortname, $adaquizname) {
    return $courseshortname . '-' . format_string($adaquizname, true) . '-' . $report;
}

/**
 * Get the default report for the current user.
 * @param object $context the adaptive quiz context.
 */
function adaquiz_report_default_report($context) {
    $reports = adaquiz_report_list($context);
    return reset($reports);
}

/**
 * Generate a message saying that this adaptive quiz has no questions, with a button to
 * go to the edit page, if the user has the right capability.
 * @param object $adaquiz the adaptive quiz settings.
 * @param object $cm the course_module object.
 * @param object $context the adaptive quiz context.
 * @return string HTML to output.
 */
function adaquiz_no_questions_message($adaquiz, $cm, $context) {
    global $OUTPUT;

    $output = '';
    $output .= $OUTPUT->notification(get_string('noquestions', 'adaquiz'));
    if (has_capability('mod/adaquiz:manage', $context)) {
        $output .= $OUTPUT->single_button(new moodle_url('/mod/adaquiz/edit.php',
        array('cmid' => $cm->id)), get_string('editquiz', 'adaquiz'), 'get');
    }

    return $output;
}

/**
 * Should the grades be displayed in this report. That depends on the adaptive quiz
 * display options, and whether the adaptive quiz is graded.
 * @param object $adaquiz the adaptive quiz settings.
 * @param context $context the adaptive quiz context.
 * @return bool
 */
function adaquiz_report_should_show_grades($adaquiz, context $context) {
    if ($adaquiz->timeclose && time() > $adaquiz->timeclose) {
        $when = mod_adaquiz_display_options::AFTER_CLOSE;
    } else {
        $when = mod_adaquiz_display_options::LATER_WHILE_OPEN;
    }
    $reviewoptions = mod_adaquiz_display_options::make_from_adaquiz($adaquiz, $when);

    return adaquiz_has_grades($adaquiz) &&
            ($reviewoptions->marks >= question_display_options::MARK_AND_MAX ||
            has_capability('moodle/grade:viewhidden', $context));
}
