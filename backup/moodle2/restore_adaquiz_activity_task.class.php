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

require_once($CFG->dirroot . '/mod/adaquiz/backup/moodle2/restore_adaquiz_stepslib.php');


/**
 * Adaptive quiz restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_adaquiz_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Adaptive uiz only has one structure step.
        $this->add_step(new restore_adaquiz_activity_structure_step('adaquiz_structure', 'adaquiz.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('adaquiz', array('intro'), 'adaquiz');
        // AdaptiveQuiz: Not feedback.
        // $contents[] = new restore_decode_content('adaquiz_feedback',
        //         array('feedbacktext'), 'adaquiz_feedback');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('ADAQUIZVIEWBYID',
                '/mod/adaquiz/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('ADAQUIZVIEWBYQ',
                '/mod/adaquiz/view.php?q=$1', 'adaquiz');
        $rules[] = new restore_decode_rule('ADAQUIZINDEX',
                '/mod/adaquiz/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * adaptive quiz logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('adaquiz', 'add',
                'view.php?id={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'update',
                'view.php?id={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'view',
                'view.php?id={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'preview',
                'view.php?id={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'report',
                'report.php?id={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'editquestions',
                'view.php?id={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'delete attempt',
                'report.php?id={course_module}', '[oldattempt]');
        $rules[] = new restore_log_rule('adaquiz', 'edit override',
                'overrideedit.php?id={adaquiz_override}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'delete override',
                'overrides.php.php?cmid={course_module}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'addcategory',
                'view.php?id={course_module}', '{question_category}');
        $rules[] = new restore_log_rule('adaquiz', 'view summary',
                'summary.php?attempt={adaquiz_attempt_id}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'manualgrade',
                'comment.php?attempt={adaquiz_attempt_id}&question={question}', '{adaquiz}');
        $rules[] = new restore_log_rule('adaquiz', 'manualgrading',
                'report.php?mode=grading&q={adaquiz}', '{adaquiz}');
        // All the ones calling to review.php have two rules to handle both old and new urls
        // in any case they are always converted to new urls on restore.
        // TODO: In Moodle 2.x (x >= 5) kill the old rules.
        // Note we are using the 'adaquiz_attempt_id' mapping because that is the
        // one containing the adaquiz_attempt->ids old an new for adaquiz-attempt.
        $rules[] = new restore_log_rule('adaquiz', 'attempt',
                'review.php?id={course_module}&attempt={adaquiz_attempt}', '{adaquiz}',
                null, null, 'review.php?attempt={adaquiz_attempt}');
        // Old an new for adaquiz-submit.
        $rules[] = new restore_log_rule('adaquiz', 'submit',
                'review.php?id={course_module}&attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, null, 'review.php?attempt={adaquiz_attempt_id}');
        $rules[] = new restore_log_rule('adaquiz', 'submit',
                'review.php?attempt={adaquiz_attempt_id}', '{adaquiz}');
        // Old an new for adaquiz-review.
        $rules[] = new restore_log_rule('adaquiz', 'review',
                'review.php?id={course_module}&attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, null, 'review.php?attempt={adaquiz_attempt_id}');
        $rules[] = new restore_log_rule('adaquiz', 'review',
                'review.php?attempt={adaquiz_attempt_id}', '{adaquiz}');
        // Old an new for adaquiz-start attemp.
        $rules[] = new restore_log_rule('adaquiz', 'start attempt',
                'review.php?id={course_module}&attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, null, 'review.php?attempt={adaquiz_attempt_id}');
        $rules[] = new restore_log_rule('adaquiz', 'start attempt',
                'review.php?attempt={adaquiz_attempt_id}', '{adaquiz}');
        // Old an new for adaquiz-close attemp.
        $rules[] = new restore_log_rule('adaquiz', 'close attempt',
                'review.php?id={course_module}&attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, null, 'review.php?attempt={adaquiz_attempt_id}');
        $rules[] = new restore_log_rule('adaquiz', 'close attempt',
                'review.php?attempt={adaquiz_attempt_id}', '{adaquiz}');
        // Old an new for adaquiz-continue attempt.
        $rules[] = new restore_log_rule('adaquiz', 'continue attempt',
                'review.php?id={course_module}&attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, null, 'review.php?attempt={adaquiz_attempt_id}');
        $rules[] = new restore_log_rule('adaquiz', 'continue attempt',
                'review.php?attempt={adaquiz_attempt_id}', '{adaquiz}');
        // Old an new for adaquiz-continue attemp.
        $rules[] = new restore_log_rule('adaquiz', 'continue attemp',
                'review.php?id={course_module}&attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, 'continue attempt', 'review.php?attempt={adaquiz_attempt_id}');
        $rules[] = new restore_log_rule('adaquiz', 'continue attemp',
                'review.php?attempt={adaquiz_attempt_id}', '{adaquiz}',
                null, 'continue attempt');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('adaquiz', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
