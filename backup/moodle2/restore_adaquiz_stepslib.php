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

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one adaquiz activity
 */
class restore_adaquiz_activity_structure_step extends restore_questions_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('adaquiz', '/activity/adaquiz');
        $paths[] = new restore_path_element('adaquiz_node', '/activity/adaquiz/nodes/node');
        $paths[] = new restore_path_element('adaquiz_jump', '/activity/adaquiz/jumps/nodeid/jump');

        if ($userinfo) {
            $adaquizattempt = new restore_path_element('adaquiz_attempt', '/activity/adaquiz/attempts/attempt');
            $paths[] = $adaquizattempt;

            $paths[] = new restore_path_element('adaquiz_node_attempt', '/activity/adaquiz/attempts/attempt/node_attempts/node_attempt');

            // Add states and sessions.
            $this->add_question_usages($adaquizattempt, $paths);
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    protected function process_adaquiz($data) {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Insert the quiz record.
        $newitemid = $DB->insert_record('adaquiz', $data);

        // Change the instance in course_module
        $this->apply_activity_instance($newitemid);
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

    protected function process_adaquiz_attempt($data){
        $data = (object)$data;

        $data->adaquiz = $this->get_new_parentid('adaquiz');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // The data is inserted into the database later in inform_new_usage_id.
        $this->currentadaquizattempt = clone($data);
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

    protected function inform_new_usage_id($newusageid) {
        global $DB;

        //Adaquiz
        $data = $this->currentadaquizattempt;

        $oldid = $data->id;
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('adaquiz_attempt', $data);
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
        // Add quiz related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_quiz', 'intro', null);
        // Add feedback related files, matching by itemname = 'quiz_feedback'.
        $this->add_related_files('mod_quiz', 'feedback', 'quiz_feedback');
    }
}
