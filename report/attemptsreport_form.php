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

require_once($CFG->libdir . '/formslib.php');


/**
 * Base class for the settings form for {@link quiz_attempts_report}s.
 */
abstract class mod_adaquiz_attempts_report_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'preferencespage',
                get_string('reportwhattoinclude', 'adaquiz'));

        $this->standard_attempt_fields($mform);
        $this->other_attempt_fields($mform);

        $mform->addElement('header', 'preferencesuser',
                get_string('reportdisplayoptions', 'adaquiz'));

        $this->standard_preference_fields($mform);
        $this->other_preference_fields($mform);

        $mform->addElement('submit', 'submitbutton',
                get_string('showreport', 'adaquiz'));
    }

    protected function standard_attempt_fields(MoodleQuickForm $mform) {

        $mform->addElement('select', 'attempts', get_string('reportattemptsfrom', 'adaquiz'), array(
                    adaquiz_attempts_report::ENROLLED_WITH    => get_string('reportuserswith', 'adaquiz'),
                    adaquiz_attempts_report::ENROLLED_WITHOUT => get_string('reportuserswithout', 'adaquiz'),
                    adaquiz_attempts_report::ENROLLED_ALL     => get_string('reportuserswithorwithout', 'adaquiz'),
                    adaquiz_attempts_report::ALL_WITH        => get_string('reportusersall', 'adaquiz'),
                 ));

        $stategroup = array(
            $mform->createElement('advcheckbox', 'stateinprogress', '',
                    get_string('stateinprogress', 'adaquiz')),
            $mform->createElement('advcheckbox', 'statefinished', '',
                    get_string('statefinished', 'adaquiz')),
        );
        $mform->addGroup($stategroup, 'stateoptions',
                get_string('reportattemptsthatare', 'adaquiz'), array(' '), false);
        $mform->setDefault('stateinprogress', 1);
        $mform->setDefault('statefinished',   1);
        $mform->disabledIf('stateinprogress', 'attempts', 'eq', adaquiz_attempts_report::ENROLLED_WITHOUT);
        $mform->disabledIf('statefinished',   'attempts', 'eq', adaquiz_attempts_report::ENROLLED_WITHOUT);

        if (quiz_report_can_filter_only_graded($this->_customdata['quiz'])) {
            $gm = html_writer::tag('span',
                    adaquiz_get_grading_option_name($this->_customdata['quiz']->grademethod),
                    array('class' => 'highlight'));
        }
    }

    protected function other_attempt_fields(MoodleQuickForm $mform) {
    }

    protected function standard_preference_fields(MoodleQuickForm $mform) {
        $mform->addElement('text', 'pagesize', get_string('pagesize', 'adaquiz'));
        $mform->setType('pagesize', PARAM_INT);
    }

    protected function other_preference_fields(MoodleQuickForm $mform) {
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if ($data['attempts'] != adaquiz_attempts_report::ENROLLED_WITHOUT && !(
                $data['stateinprogress'] || $data['statefinished'])) {
            $errors['stateoptions'] = get_string('reportmustselectstate', 'adaquiz');
        }

        return $errors;
    }
}
