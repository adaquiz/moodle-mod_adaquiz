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
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();


/**
 * Structure step to restore one adaptive quiz activity
 *
 */
class restore_adaquiz_activity_structure_step extends restore_questions_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $adaquiz = new restore_path_element('adaquiz', '/activity/adaquiz');
        $paths[] = $adaquiz;

        // A chance for access subplugings to set up their adaptive quiz data.
        // $this->add_subplugin_structure('adaquizaccess', $adaquiz);

        $paths[] = new restore_path_element('adaquiz_question_instance',
                '/activity/adaquiz/question_instances/question_instance');
        // AdaptiveQuiz.
        // $paths[] = new restore_path_element('adaquiz_feedback', '/activity/adaquiz/feedbacks/feedback');
        // $paths[] = new restore_path_element('adaquiz_override', '/activity/adaquiz/overrides/override');
        $paths[] = new restore_path_element('adaquiz_node', '/activity/adaquiz/nodes/node');
        $paths[] = new restore_path_element('adaquiz_jump', '/activity/adaquiz/jumps/nodeid/jump');

        if ($userinfo) {
            // AdaptiveQuiz.
            // $paths[] = new restore_path_element('adaquiz_grade', '/activity/adaquiz/grades/grade');

            if ($this->task->get_old_moduleversion() > 2011010100) {
                // Restoring from a version 2.1 dev or later.
                // Process the new-style attempt data.
                $adaquizattempt = new restore_path_element('adaquiz_attempt',
                        '/activity/adaquiz/attempts/attempt');
                $paths[] = $adaquizattempt;
                $adaquiznodeattempt = new restore_path_element('adaquiz_node_attempt', '/activity/adaquiz/attempts/attempt/node_attempts/node_attempt');
                $paths[] = $adaquiznodeattempt;
                // Add states and sessions.
                $this->add_question_usages($adaquizattempt, $paths);

                // A chance for access subplugings to set up their attempt data.
                // $this->add_subplugin_structure('adaquizaccess', $adaquizattempt);

            } else {
                // Restoring from a version 2.0.x+ or earlier.
                // Upgrade the legacy attempt data.
                $adaquizattempt = new restore_path_element('adaquiz_attempt_legacy',
                        '/activity/adaquiz/attempts/attempt',
                        true);
                $paths[] = $adaquizattempt;
                $this->add_legacy_question_attempt_data($adaquizattempt, $paths);
            }
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_adaquiz($data) {
        global $CFG, $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        if (property_exists($data, 'questions')) {
            // Needed by {@link process_adaquiz_attempt_legacy}, in which case it will be present.
            $this->oldquizlayout = $data->questions;
        }

        // The setting adaquiz->attempts can come both in data->attempts and
        // data->attempts_number, handle both. MDL-26229.
        // if (isset($data->attempts_number)) {
        //     $data->attempts = $data->attempts_number;
        //     unset($data->attempts_number);
        // }

        // // The old optionflags and penaltyscheme from 2.0 need to be mapped to
        // // the new preferredbehaviour. See MDL-20636.
        // if (!isset($data->preferredbehaviour)) {
        //     if (empty($data->optionflags)) {
        //         $data->preferredbehaviour = 'deferredfeedback';
        //     } else if (empty($data->penaltyscheme)) {
        //         $data->preferredbehaviour = 'adaptivenopenalty';
        //     } else {
        //         $data->preferredbehaviour = 'adaptive';
        //     }
        //     unset($data->optionflags);
        //     unset($data->penaltyscheme);
        // }

        // // The old review column from 2.0 need to be split into the seven new
        // // review columns. See MDL-20636.
        // if (isset($data->review)) {
        //     require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

        //     if (!defined('ADAQUIZ_OLD_IMMEDIATELY')) {
        //         define('ADAQUIZ_OLD_IMMEDIATELY', 0x3c003f);
        //         define('ADAQUIZ_OLD_OPEN',        0x3c00fc0);
        //         define('ADAQUIZ_OLD_CLOSED',      0x3c03f000);

        //         define('ADAQUIZ_OLD_RESPONSES',        1*0x1041);
        //         define('ADAQUIZ_OLD_SCORES',           2*0x1041);
        //         define('ADAQUIZ_OLD_FEEDBACK',         4*0x1041);
        //         define('ADAQUIZ_OLD_ANSWERS',          8*0x1041);
        //         define('ADAQUIZ_OLD_SOLUTIONS',       16*0x1041);
        //         define('ADAQUIZ_OLD_GENERALFEEDBACK', 32*0x1041);
        //         define('ADAQUIZ_OLD_OVERALLFEEDBACK',  1*0x4440000);
        //     }

        //     $oldreview = $data->review;

        //     $data->reviewattempt =
        //             mod_adaquiz_display_options::DURING |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_RESPONSES ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_RESPONSES ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_RESPONSES ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);

        //     $data->reviewcorrectness =
        //             mod_adaquiz_display_options::DURING |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_SCORES ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_SCORES ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_SCORES ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);

        //     $data->reviewmarks =
        //             mod_adaquiz_display_options::DURING |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_SCORES ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_SCORES ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_SCORES ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);

        //     $data->reviewspecificfeedback =
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_FEEDBACK ?
        //                     mod_adaquiz_display_options::DURING : 0) |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_FEEDBACK ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_FEEDBACK ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_FEEDBACK ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);

        //     $data->reviewgeneralfeedback =
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_GENERALFEEDBACK ?
        //                     mod_adaquiz_display_options::DURING : 0) |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_GENERALFEEDBACK ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_GENERALFEEDBACK ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_GENERALFEEDBACK ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);

        //     $data->reviewrightanswer =
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_ANSWERS ?
        //                     mod_adaquiz_display_options::DURING : 0) |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_ANSWERS ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_ANSWERS ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_ANSWERS ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);

        //     $data->reviewoverallfeedback =
        //             0 |
        //             ($oldreview & ADAQUIZ_OLD_IMMEDIATELY & ADAQUIZ_OLD_OVERALLFEEDBACK ?
        //                     mod_adaquiz_display_options::IMMEDIATELY_AFTER : 0) |
        //             ($oldreview & ADAQUIZ_OLD_OPEN & ADAQUIZ_OLD_OVERALLFEEDBACK ?
        //                     mod_adaquiz_display_options::LATER_WHILE_OPEN : 0) |
        //             ($oldreview & ADAQUIZ_OLD_CLOSED & ADAQUIZ_OLD_OVERALLFEEDBACK ?
        //                     mod_adaquiz_display_options::AFTER_CLOSE : 0);
        // }

        // // The old popup column from from <= 2.1 need to be mapped to
        // // the new browsersecurity. See MDL-29627.
        // if (!isset($data->browsersecurity)) {
        //     if (empty($data->popup)) {
        //         $data->browsersecurity = '-';
        //     } else if ($data->popup == 1) {
        //         $data->browsersecurity = 'securewindow';
        //     } else if ($data->popup == 2) {
        //         $data->browsersecurity = 'safebrowser';
        //     } else {
        //         $data->preferredbehaviour = '-';
        //     }
        //     unset($data->popup);
        // }

        // if (!isset($data->overduehandling)) {
        //     $data->overduehandling = get_config('adaquiz', 'overduehandling');
        // }

        // Insert the adaptive quiz record.
        $newitemid = $DB->insert_record('adaquiz', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    protected function process_adaquiz_question_instance($data) {
        global $DB;

        $data = (object)$data;

        // Backwards compatibility for old field names (MDL-43670).
        if (!isset($data->questionid) && isset($data->question)) {
            $data->questionid = $data->question;
        }
        if (!isset($data->maxmark) && isset($data->grade)) {
            $data->maxmark = $data->grade;
        }

        if (!property_exists($data, 'slot')) {
            $page = 1;
            $slot = 1;
            foreach (explode(',', $this->oldquizlayout) as $item) {
                if ($item == 0) {
                    $page += 1;
                    continue;
                }
                if ($item == $data->questionid) {
                    $data->slot = $slot;
                    $data->page = $page;
                    break;
                }
                $slot += 1;
            }
        }

        if (!property_exists($data, 'slot')) {
            // There was a question_instance in the backup file for a question
            // that was not acutally in the adaptive quiz. Drop it.
            $this->log('question ' . $data->questionid . ' was associated with adaptive quiz ' .
                    $this->get_new_parentid('adaquiz') . ' but not actually used. ' .
                    'The instance has been ignored.', backup::LOG_INFO);
            return;
        }

        $data->adaquizid = $this->get_new_parentid('adaquiz');
        $data->questionid = $this->get_mappingid('question', $data->questionid);

        $DB->insert_record('adaquiz_slots', $data);
    }

    // protected function process_adaquiz_feedback($data) {
    //     global $DB;

    //     $data = (object)$data;
    //     $oldid = $data->id;

    //     $data->adaquizid = $this->get_new_parentid('adaquiz');

    //     $newitemid = $DB->insert_record('adaquiz_feedback', $data);
    //     $this->set_mapping('adaquiz_feedback', $oldid, $newitemid, true); // Has related files.
    // }

    // protected function process_adaquiz_override($data) {
    //     global $DB;

    //     $data = (object)$data;
    //     $oldid = $data->id;

    //     // Based on userinfo, we'll restore user overides or no.
    //     $userinfo = $this->get_setting_value('userinfo');

    //     // Skip user overrides if we are not restoring userinfo.
    //     if (!$userinfo && !is_null($data->userid)) {
    //         return;
    //     }

    //     $data->adaquiz = $this->get_new_parentid('adaquiz');

    //     if ($data->userid !== null) {
    //         $data->userid = $this->get_mappingid('user', $data->userid);
    //     }

    //     if ($data->groupid !== null) {
    //         $data->groupid = $this->get_mappingid('group', $data->groupid);
    //     }

    //     $data->timeopen = $this->apply_date_offset($data->timeopen);
    //     $data->timeclose = $this->apply_date_offset($data->timeclose);

    //     $newitemid = $DB->insert_record('adaquiz_overrides', $data);

    //     // Add mapping, restore of logs needs it.
    //     $this->set_mapping('adaquiz_override', $oldid, $newitemid);
    // }

    // protected function process_adaquiz_grade($data) {
    //     global $DB;

    //     $data = (object)$data;
    //     $oldid = $data->id;

    //     $data->adaquiz = $this->get_new_parentid('adaquiz');

    //     $data->userid = $this->get_mappingid('user', $data->userid);
    //     $data->grade = $data->gradeval;

    //     $data->timemodified = $this->apply_date_offset($data->timemodified);

    //     $DB->insert_record('adaquiz_grades', $data);
    // }

    protected function process_adaquiz_attempt($data) {
        $data = (object)$data;

        $data->quiz = $this->get_new_parentid('adaquiz');
        // $data->attempt = $data->attemptnum;

        $data->userid = $this->get_mappingid('user', $data->userid);

        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timefinish = $this->apply_date_offset($data->timefinish);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        if (!empty($data->timecheckstate)) {
            $data->timecheckstate = $this->apply_date_offset($data->timecheckstate);
        } else {
            $data->timecheckstate = 0;
        }

        // Deals with up-grading pre-2.3 back-ups to 2.3+.
        if (!isset($data->state)) {
            if ($data->timefinish > 0) {
                $data->state = 'finished';
            } else {
                $data->state = 'inprogress';
            }
        }

        // The data is actually inserted into the database later in inform_new_usage_id.
        $this->currentquizattempt = clone($data);
    }

    protected function process_adaquiz_node($data){
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->adaquiz = $this->get_new_parentid('adaquiz');

        $data->question = $this->get_mappingid('question', $data->question);

        $newitemid = $DB->insert_record('adaquiz_node', $data);
        //Set the new id
        $this->set_mapping('adaquiz_node', $oldid, $newitemid, true);
    }

    protected function process_adaquiz_jump($data){
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $test = $this->get_new_parentid('adaquiz_node');

        $data->nodefrom = $this->get_mappingid('adaquiz_node', $data->nodefrom);
        if ($data->nodeto != 0){
            $data->nodeto = $this->get_mappingid('adaquiz_node', $data->nodeto);
        }

        $newitemid = $DB->insert_record('adaquiz_jump', $data);
        $this->set_mapping('adaquiz_jump', $oldid, $newitemid, true);
    }

    protected function process_adaquiz_node_attempt($data){
        $data = (object)$data;

        $data->node = $this->get_mappingid('adaquiz_node', $data->node);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->jump = $this->get_mappingid('adaquiz_jump', $data->jump);

        // The data is inserted into the database later in inform_new_usage_id.
        $this->currentnodeattempt[] = clone($data);
    }

    protected function process_adaquiz_attempt_legacy($data) {
        global $DB;

        $this->process_adaquiz_attempt($data);

        $adaquiz = $DB->get_record('quiz', array('id' => $this->get_new_parentid('quiz')));
        $adaquiz->oldquestions = $this->oldquizlayout;
        $this->process_legacy_adaquiz_attempt_data($data, $adaquiz);
    }

    protected function inform_new_usage_id($newusageid) {
        global $DB;

        $data = $this->currentquizattempt;

        $oldid = $data->id;
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('adaquiz_attempts', $data);

        // Save adaquiz_attempt->id mapping, because logs use it.
        $this->set_mapping('adaquiz_attempt', $oldid, $newitemid, false);

        //Single Node attempts
        foreach ($this->currentnodeattempt as $value){
            $data = $value;
            $data->attempt = $this->get_new_parentid('adaquiz_attempt');
            $DB->insert_record('adaquiz_node_attempt', $data);
        }
    }

    protected function after_execute() {
        parent::after_execute();
        // Add adaquiz related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_adaquiz', 'intro', null);
        // Add feedback related files, matching by itemname = 'adaquiz_feedback'.
        // $this->add_related_files('mod_adaquiz', 'feedback', 'adaquiz_feedback');
    }
}
