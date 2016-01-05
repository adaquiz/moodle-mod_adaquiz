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
 * Define all the backup steps that will be used by the backup_adaquiz_activity_task
 *
 */
class backup_adaquiz_activity_structure_step extends backup_questions_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $adaquiz = new backup_nested_element('adaquiz', array('id'), array(
            'name', 'intro', 'introformat', 'timeopen', 'timeclose', 'timelimit',
            'overduehandling', 'graceperiod', 'preferredbehaviour', 'attempts_number',
            'attemptonlast', 'grademethod', 'decimalpoints', 'questiondecimalpoints',
            'reviewattempt', 'reviewcorrectness', 'reviewmarks',
            'reviewspecificfeedback', 'reviewgeneralfeedback',
            'reviewrightanswer', 'reviewoverallfeedback',
            'questionsperpage', 'navmethod', 'shufflequestions', 'shuffleanswers',
            'sumgrades', 'grade', 'timecreated',
            'timemodified', 'password', 'subnet', 'browsersecurity',
            'delay1', 'delay2', 'showuserpicture', 'showblocks', 'completionattemptsexhausted', 'completionpass'));

        // Define elements for access rule subplugin settings.
        // $this->add_subplugin_structure('adaquizaccess', $adaquiz, true);

        $nodes = new backup_nested_element('nodes');
        $node = new backup_nested_element('node', array('id'), array('question', 'adaquiz', 'position', 'grade', 'options'));

        $jumps = new backup_nested_element('jumps');
        $nodeid = new backup_nested_element('nodeid', array('id'));
        $jump = new backup_nested_element('jump', array('id'), array('type', 'position', 'name', 'nodefrom', 'nodeto', 'options'));

        $qinstances = new backup_nested_element('question_instances');

        $qinstance = new backup_nested_element('question_instance', array('id'), array(
            'slot', 'page', 'questionid', 'maxmark'));

        // $feedbacks = new backup_nested_element('feedbacks');

        // $feedback = new backup_nested_element('feedback', array('id'), array(
        //     'feedbacktext', 'feedbacktextformat', 'mingrade', 'maxgrade'));

        // $overrides = new backup_nested_element('overrides');

        // $override = new backup_nested_element('override', array('id'), array(
        //     'userid', 'groupid', 'timeopen', 'timeclose',
        //     'timelimit', 'attempts', 'password'));

        // $grades = new backup_nested_element('grades');

        // $grade = new backup_nested_element('grade', array('id'), array(
        //     'userid', 'gradeval', 'timemodified'));

        $attempts = new backup_nested_element('attempts');

        $attempt = new backup_nested_element('attempt', array('id'), array(
            'userid', 'uniqueid', 'preview',
            'state', 'timestart', 'timefinish', 'timemodified', 'timecheckstate', 'sumgrades', 'seed'));

        $nodeattempts = new backup_nested_element('node_attempts');
        $nodeattempt = new backup_nested_element('node_attempt', array('id'), array('attempt', 'node', 'timecreated', 'timemodified',
            'grade', 'position', 'jump', 'uniqueid'));

        // This module is using questions, so produce the related question states and sessions
        // attaching them to the $attempt element based in 'uniqueid' matching.
        $this->add_question_usages($attempt, 'uniqueid');

        // Define elements for access rule subplugin attempt data.
        // $this->add_subplugin_structure('adaquizaccess', $attempt, true);

        // Build the tree.
        $adaquiz->add_child($qinstances);
        $qinstances->add_child($qinstance);

        // $adaquiz->add_child($feedbacks);
        // $feedbacks->add_child($feedback);

        // $adaquiz->add_child($overrides);
        // $overrides->add_child($override);

        // $adaquiz->add_child($grades);
        // $grades->add_child($grade);

        $adaquiz->add_child($nodes);
        $nodes->add_child($node);

        $adaquiz->add_child($jumps);
        $jumps->add_child($nodeid);
        $nodeid->add_child($jump);

        $adaquiz->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($nodeattempts);
        $nodeattempts->add_child($nodeattempt);

        // Define sources.
        $adaquiz->set_source_table('adaquiz', array('id' => backup::VAR_ACTIVITYID));
        $node->set_source_table('adaquiz_node', array('adaquiz' => backup::VAR_PARENTID));
        $nodeid->set_source_table('adaquiz_node', array('adaquiz' => backup::VAR_PARENTID));
        $jump->set_source_table('adaquiz_jump', array('nodefrom' => backup::VAR_PARENTID));


        $qinstance->set_source_table('adaquiz_slots',
                array('adaquizid' => backup::VAR_PARENTID));

        // AdaptiveQuiz. Nt feedback
        // $feedback->set_source_table('adaquiz_feedback',
        //         array('adaquizid' => backup::VAR_PARENTID));

        // Adaptive quiz overrides to backup are different depending of user info.
        // $overrideparams = array('quiz' => backup::VAR_PARENTID);
        // if (!$userinfo) { //  Without userinfo, skip user overrides.
        //     $overrideparams['userid'] = backup_helper::is_sqlparam(null);

        // }
        // AdaptiveQuiz: Not overrides.
        // $override->set_source_table('adaquiz_overrides', $overrideparams);

        // AdaptiveQuiz: Not adaquiz_grades.
        // All the rest of elements only happen if we are including user info.
        if ($userinfo) {
            // $grade->set_source_table('adaquiz_grades', array('quiz' => backup::VAR_PARENTID));
            $attempt->set_source_sql('
                    SELECT *
                    FROM {adaquiz_attempts}
                    WHERE quiz = :adaquiz AND preview = 0',
                    array('adaquiz' => backup::VAR_PARENTID));
            $nodeattempt->set_source_table('adaquiz_node_attempt', array('attempt' => backup::VAR_PARENTID));
        }

        // Define source alias.
        // $adaquiz->set_source_alias('attempts', 'attempts_number');
        // $grade->set_source_alias('grade', 'gradeval');
        // $attempt->set_source_alias('attempt', 'attemptnum');

        // Define id annotations.
        $qinstance->annotate_ids('question', 'questionid');
        // $override->annotate_ids('user', 'userid');
        // $override->annotate_ids('group', 'groupid');
        // $grade->annotate_ids('user', 'userid');
        $attempt->annotate_ids('user', 'userid');

        // Define file annotations.
        $adaquiz->annotate_files('mod_adaquiz', 'intro', null); // This file area hasn't itemid.
        // $feedback->annotate_files('mod_adaquiz', 'feedback', 'id');

        // Return the root element (adaquiz), wrapped into standard activity structure.
        return $this->prepare_activity_structure($adaquiz);
    }
}
