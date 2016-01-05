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
 * This script lists all the instances of adaptive quiz in a particular course
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/adaquiz/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

$params = array(
    'context' => $coursecontext
);
$event = \mod_adaquiz\event\course_module_instance_list_viewed::create($params);
$event->trigger();

// Print the header.
$strquizzes = get_string("modulenameplural", "adaquiz");
$streditquestions = '';
$editqcontexts = new question_edit_contexts($coursecontext);
if ($editqcontexts->have_one_edit_tab_cap('questions')) {
    $streditquestions =
            "<form target=\"_parent\" method=\"get\" action=\"$CFG->wwwroot/question/edit.php\">
               <div>
               <input type=\"hidden\" name=\"courseid\" value=\"$course->id\" />
               <input type=\"submit\" value=\"".get_string("editquestions", "adaquiz")."\" />
               </div>
             </form>";
}
$PAGE->navbar->add($strquizzes);
$PAGE->set_title($strquizzes);
$PAGE->set_button($streditquestions);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($strquizzes, 2);

// Get all the appropriate data.
if (!$adaquizzes = get_all_instances_in_course("adaquiz", $course)) {
    notice(get_string('thereareno', 'moodle', $strquizzes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the closing date header.
$showclosingheader = false;
$showfeedback = false;
foreach ($adaquizzes as $adaquiz) {
    if ($adaquiz->timeclose!=0) {
        $showclosingheader=true;
    }
    if (adaquiz_has_feedback($adaquiz)) {
        $showfeedback=true;
    }
    if ($showclosingheader && $showfeedback) {
        break;
    }
}

// Configure table for displaying the list of instances.
$headings = array(get_string('name'));
$align = array('left');

if ($showclosingheader) {
    array_push($headings, get_string('quizcloses', 'adaquiz'));
    array_push($align, 'left');
}

if (course_format_uses_sections($course->format)) {
    array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
} else {
    array_unshift($headings, '');
}
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/adaquiz:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'adaquiz'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/adaquiz:reviewmyattempts', 'mod/adaquiz:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'adaquiz'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'adaquiz'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.quiz, qg.grade
            FROM {adaquiz_grades} qg
            JOIN {adaquiz} q ON q.id = qg.quiz
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
foreach ($adaquizzes as $adaquiz) {
    $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($adaquiz->section != $currentsection) {
        if ($adaquiz->section) {
            $strsection = $adaquiz->section;
            $strsection = get_section_name($course, $adaquiz->section);
        }
        if ($currentsection) {
            $learningtable->data[] = 'hr';
        }
        $currentsection = $adaquiz->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$adaquiz->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$adaquiz->coursemodule\">" .
            format_string($adaquiz->name, true) . '</a>';

    // Close date.
    if ($adaquiz->timeclose) {
        $data[] = userdate($adaquiz->timeclose);
    } else if ($showclosingheader) {
        $data[] = '';
    }

    if ($showing == 'stats') {
        // The $adaquiz objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = adaquiz_attempt_summary_link_to_reports($adaquiz, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = adaquiz_get_user_attempts($adaquiz->id, $USER->id, 'all');
        list($someoptions, $alloptions) = adaquiz_get_combined_reviewoptions(
                $adaquiz, $attempts, $context);

        $grade = '';
        $feedback = '';
        if ($adaquiz->grade && array_key_exists($adaquiz->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = adaquiz_format_grade($adaquiz, $grades[$adaquiz->id]);
                $a->maxgrade = adaquiz_format_grade($adaquiz, $adaquiz->grade);
                $grade = get_string('outofshort', 'adaquiz', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = adaquiz_feedback_for_grade($grades[$adaquiz->id], $adaquiz, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over adaptive quiz instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
