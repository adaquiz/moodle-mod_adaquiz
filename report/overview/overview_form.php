<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/adaquiz/report/attemptsreport_form.php');


/**
 * Quiz overview report settings form.
 *
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adaquiz_overview_settings_form extends mod_adaquiz_attempts_report_form {

    protected function other_attempt_fields(MoodleQuickForm $mform) {
        /*if (has_capability('mod/adaquiz:regrade', $this->_customdata['context'])) {
            $mform->addElement('advcheckbox', 'onlyregraded', '',
                    get_string('optonlyregradedattempts', 'quiz_overview'));
            $mform->disabledIf('onlyregraded', 'attempts', 'eq', adaquiz_attempts_report::ENROLLED_WITHOUT);
        }*/
    }

    protected function other_preference_fields(MoodleQuickForm $mform) {
        /*if (adaquiz_has_grades($this->_customdata['quiz'])) {
            $mform->addElement('selectyesno', 'slotmarks',
                    get_string('showdetailedmarks', 'quiz_overview'));
        } else {
            $mform->addElement('hidden', 'slotmarks', 0);
        }*/
        $mform->addElement('hidden', 'slotmarks', 0);
        $mform->setType('slotmarks', PARAM_INT);

    }
}
