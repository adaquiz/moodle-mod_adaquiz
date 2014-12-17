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
 * Define all the backup steps that will be used by the backup_adaquiz_activity_task
 *
 */
class backup_adaquiz_activity_structure_step extends backup_questions_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $adaquiz = new backup_nested_element('adaquiz', array('id'), array('course',
        'name', 'intro', 'introformat', 'timecreated', 'timemodified', 'grade',
        'options'));


        $nodes = new backup_nested_element('nodes');
        $node = new backup_nested_element('node', array('id'), array('question', 'adaquiz', 'position', 'grade', 'options'));

        $jumps = new backup_nested_element('jumps');
        $nodeid = new backup_nested_element('nodeid', array('id'));
        $jump = new backup_nested_element('jump', array('id'), array('type', 'position', 'name', 'nodefrom', 'nodeto', 'options'));

        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', array('id'), array('userid', 'timecreated', 'timemodified',
            'adaquiz', 'preview', 'state', 'sumgrades', 'uniqueid', 'seed'));

        $nodeattempts = new backup_nested_element('node_attempts');
        $nodeattempt = new backup_nested_element('node_attempt', array('id'), array('attempt', 'node', 'timecreated', 'timemodified',
            'grade', 'position', 'jump', 'uniqueid'));

        // Build the tree.
        $adaquiz->add_child($nodes);
        $nodes->add_child($node);

        $adaquiz->add_child($jumps);
        $jumps->add_child($nodeid);
        $nodeid->add_child($jump);

        $adaquiz->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($nodeattempts);
        $nodeattempts->add_child($nodeattempt);
        $this->add_question_usages($attempt, 'uniqueid');

        // Define sources.
        $adaquiz->set_source_table('adaquiz', array('id' => backup::VAR_ACTIVITYID));
        $node->set_source_table('adaquiz_node', array('adaquiz' => backup::VAR_PARENTID));
        $nodeid->set_source_table('adaquiz_node', array('adaquiz' => backup::VAR_PARENTID));
        $jump->set_source_table('adaquiz_jump', array('nodefrom' => backup::VAR_PARENTID));

        if ($userinfo){
            $attempt->set_source_sql('SELECT * FROM {adaquiz_attempt} WHERE adaquiz = :adaquiz AND preview = 0', array('adaquiz' => backup::VAR_PARENTID));
            $nodeattempt->set_source_table('adaquiz_node_attempt', array('attempt' => backup::VAR_PARENTID));
        }

        return $this->prepare_activity_structure($adaquiz);
    }
}
