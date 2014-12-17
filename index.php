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

/**
 * It prints a table with the list of all adaquizzes in one course. It is a
 * simplified version of /quiz/index.php.
 * **/

 require_once('../../config.php');
 require_once($CFG->dirroot.'/mod/adaquiz/lib/Adaquiz.php');
 
 global $DB, $OUTPUT, $PAGE;

 $id = required_param('id', PARAM_INT);
 if (!$course = $DB->get_record('course', array('id' => $id))) {
   error("Course ID is incorrect");
 }
 $coursecontext = context_course::instance($id);
 require_login($course->id);
 $PAGE->set_url('/mod/adaquiz/index.php', array('id' => $id));
 $stradaquizzes = get_string("modulenameplural", "adaquiz");
 $PAGE->navbar->add($stradaquizzes);
 $PAGE->set_title($stradaquizzes);
 $PAGE->set_heading($course->fullname);
 $PAGE->set_pagelayout('incourse');
 
 echo $OUTPUT->header();
 
 //Get data
 if (!$adas = get_all_instances_in_course('adaquiz', $course)) {
    notice(get_string('noadaquizzes', 'adaquiz'), "../../course/view.php?id=$course->id");
    die;
 }else{
    //Print table
    $table = new html_table();
    //headings
    $table->head = array();
    if ($course->format == 'weeks' or $course->format == 'weekscss') {
        $table->head[] = get_string('week');
    }else{
        $table->head[] = get_string('section');
    }
    $table->head[] = get_string('name');
    if(has_capability('mod/adaquiz:viewreports', $coursecontext)){
        $table->head[] = get_string('attempts', 'adaquiz');
    }else{
        $table->head[] = get_string('grade', 'adaquiz');
    }
    $table->align = array('center', 'left', 'left');
    //rows
    $currentsection = ''; //only print section for the first adaquiz.
    $table->data = array();
    foreach ($adas as $ada) {
        $adaquiz = new Adaquiz($ada);
        $cm = get_coursemodule_from_instance('adaquiz',$ada->id);
        $context = context_module::instance($cm->id);
        $data = array();
        //section
        if($cm->section != $currentsection){
            $currentsection = $cm->section;
            $section = $DB->get_field('course_sections', 'section', array('id' => $cm->section));
            $data[] = $section;
        }else{
            $data[] = '';
        }
        //name + link to instance
        $class = '';
        if (!$cm->visible) {
            $class = 'class="dimmed"';
        }
        $data[] = "<a $class href=\"view.php?id=$cm->id\">" . format_string($ada->name, true) . '</a>';
        if(has_capability('mod/adaquiz:viewreports', $coursecontext)){
            $numattempts = $adaquiz->getNumAttempts();
            if($numattempts > 0){
                $data[] = '<a href="'.$CFG->wwwroot.'/mod/adaquiz/view.php?aq='.$ada->id.'&op=report">'
                            .get_string('attempts', 'adaquiz').': '.$numattempts.'</a>';
            }else{
                $data[] = '';
            }
        }else{
            global $USER;
            if(!function_exists('grade_get_grades')) {
                require_once($CFG->libdir.'/gradelib.php');
            }
            $grades = grade_get_grades($adaquiz->course, 'mod', 'adaquiz', $adaquiz->id, $USER->id);
            if($grades->items[0]->grades[$USER->id]){
                $data[] = $grades->items[0]->grades[$USER->id]->str_long_grade;
            }else{
                $data[] = '';
            }
        }
        $table->data[] = $data;
    }     
    //Display the table.
    echo '<br />';
    echo html_writer::table($table);    
 }

    //Finish the page
    echo $OUTPUT->footer();
?>