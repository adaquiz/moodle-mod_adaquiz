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
 * Unit tests for (some of) mod/adaquiz/locallib.php.
 *
 * @package   mod_adaquiz
 * @category  test
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');


/**
 * Unit tests for (some of) mod/adaquiz/locallib.php.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_adaquiz_locallib_testcase extends basic_testcase {

    public function test_adaquiz_rescale_grade() {
        $adaquiz = new stdClass();
        $adaquiz->decimalpoints = 2;
        $adaquiz->questiondecimalpoints = 3;
        $adaquiz->grade = 10;
        $adaquiz->sumgrades = 10;
        $this->assertEquals(adaquiz_rescale_grade(0.12345678, $adaquiz, false), 0.12345678);
        $this->assertEquals(adaquiz_rescale_grade(0.12345678, $adaquiz, true), format_float(0.12, 2));
        $this->assertEquals(adaquiz_rescale_grade(0.12345678, $adaquiz, 'question'),
            format_float(0.123, 3));
        $adaquiz->sumgrades = 5;
        $this->assertEquals(adaquiz_rescale_grade(0.12345678, $adaquiz, false), 0.24691356);
        $this->assertEquals(adaquiz_rescale_grade(0.12345678, $adaquiz, true), format_float(0.25, 2));
        $this->assertEquals(adaquiz_rescale_grade(0.12345678, $adaquiz, 'question'),
            format_float(0.247, 3));
    }

    public function test_adaquiz_attempt_state_in_progress() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::IN_PROGRESS;
        $attempt->timefinish = 0;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = 0;

        $this->assertEquals(mod_adaquiz_display_options::DURING, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_recently_submitted() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 10;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = 0;

        $this->assertEquals(mod_adaquiz_display_options::IMMEDIATELY_AFTER, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_sumitted_adaquiz_never_closes() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = 0;

        $this->assertEquals(mod_adaquiz_display_options::LATER_WHILE_OPEN, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_sumitted_adaquiz_closes_later() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = time() + 3600;

        $this->assertEquals(mod_adaquiz_display_options::LATER_WHILE_OPEN, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_sumitted_adaquiz_closed() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::FINISHED;
        $attempt->timefinish = time() - 7200;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = time() - 3600;

        $this->assertEquals(mod_adaquiz_display_options::AFTER_CLOSE, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_never_sumitted_adaquiz_never_closes() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::ABANDONED;
        $attempt->timefinish = 1000; // A very long time ago!

        $adaquiz = new stdClass();
        $adaquiz->timeclose = 0;

        $this->assertEquals(mod_adaquiz_display_options::LATER_WHILE_OPEN, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_never_sumitted_adaquiz_closes_later() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::ABANDONED;
        $attempt->timefinish = time() - 7200;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = time() + 3600;

        $this->assertEquals(mod_adaquiz_display_options::LATER_WHILE_OPEN, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_attempt_state_never_sumitted_adaquiz_closed() {
        $attempt = new stdClass();
        $attempt->state = adaquiz_attempt::ABANDONED;
        $attempt->timefinish = time() - 7200;

        $adaquiz = new stdClass();
        $adaquiz->timeclose = time() - 3600;

        $this->assertEquals(mod_adaquiz_display_options::AFTER_CLOSE, adaquiz_attempt_state($adaquiz, $attempt));
    }

    public function test_adaquiz_question_tostring() {
        $question = new stdClass();
        $question->qtype = 'multichoice';
        $question->name = 'The question name';
        $question->questiontext = '<p>What sort of <b>inequality</b> is x &lt; y<img alt="?" src="..."></p>';
        $question->questiontextformat = FORMAT_HTML;

        $summary = adaquiz_question_tostring($question);
        $this->assertEquals('<span class="questionname">The question name</span> ' .
                '<span class="questiontext">What sort of INEQUALITY is x &lt; y[?]</span>', $summary);
    }
}
