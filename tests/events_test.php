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
 * Adaptive quiz events tests.
 *
 * @package   mod_adaquiz
 * @category  test
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaquiz/attemptlib.php');

/**
 * Unit tests for adaptive quiz events.
 *
 * @package    mod_adaquiz
 * @category   phpunit
 * @copyright  2013 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_adaquiz_events_testcase extends advanced_testcase {

    protected function prepare_adaquiz_data() {

        $this->resetAfterTest(true);

        // Create a course
        $course = $this->getDataGenerator()->create_course();

        // Make an adaptive quiz.
        $adaquizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_adaquiz');

        $adaquiz = $adaquizgenerator->create_instance(array('course'=>$course->id, 'questionsperpage' => 0,
            'grade' => 100.0, 'sumgrades' => 2));

        $cm = get_coursemodule_from_instance('adaquiz', $adaquiz->id, $course->id);

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));

        // Add them to the adaptive quiz.
        adaquiz_add_adaquiz_question($saq->id, $adaquiz);
        adaquiz_add_adaquiz_question($numq->id, $adaquiz);

        // Make a user to do the adaptive quiz.
        $user1 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);

        $adaquizobj = adaquiz::create($adaquiz->id, $user1->id);

        // Start the attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_adaquiz', $adaquizobj->get_context());
        $quba->set_preferred_behaviour($adaquizobj->get_adaquiz()->preferredbehaviour);

        $timenow = time();
        $attempt = adaquiz_create_attempt($adaquizobj, 1, false, $timenow);
        adaquiz_start_new_attempt($adaquizobj, $quba, $attempt, 1, $timenow);
        adaquiz_attempt_save_started($adaquizobj, $quba, $attempt);

        return array($adaquizobj, $quba, $attempt);
    }

    public function test_attempt_submitted() {

        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();
        $attemptobj = adaquiz_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();

        $timefinish = time();
        $attemptobj->process_finish($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        // Validate the event.
        $this->assertCount(3, $events);
        $event = $events[2];
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_submitted', $event);
        $this->assertEquals('adaquiz_attempts', $event->objecttable);
        $this->assertEquals($adaquizobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertEquals(null, $event->other['submitterid']); // Should be the user, but PHP Unit complains...
        $this->assertEquals('adaquiz_attempt_submitted', $event->get_legacy_eventname());
        $legacydata = new stdClass();
        $legacydata->component = 'mod_adaquiz';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $adaquizobj->get_cmid();
        $legacydata->courseid = $adaquizobj->get_courseid();
        $legacydata->adaquizid = $adaquizobj->get_adaquizid();
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $legacydata->submitterid = null;
        $legacydata->timefinish = $timefinish;
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_becameoverdue() {

        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();
        $attemptobj = adaquiz_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();
        $timefinish = time();
        $attemptobj->process_going_overdue($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_becameoverdue', $event);
        $this->assertEquals('adaquiz_attempts', $event->objecttable);
        $this->assertEquals($adaquizobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertNotEmpty($event->get_description());
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $this->assertEquals(null, $event->other['submitterid']);
        $this->assertEquals('adaquiz_attempt_overdue', $event->get_legacy_eventname());
        $legacydata = new stdClass();
        $legacydata->component = 'mod_adaquiz';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $adaquizobj->get_cmid();
        $legacydata->courseid = $adaquizobj->get_courseid();
        $legacydata->adaquizid = $adaquizobj->get_adaquizid();
        $legacydata->submitterid = null; // Should be the user, but PHP Unit complains...
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_abandoned() {

        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();
        $attemptobj = adaquiz_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();
        $timefinish = time();
        $attemptobj->process_abandon($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_abandoned', $event);
        $this->assertEquals('adaquiz_attempts', $event->objecttable);
        $this->assertEquals($adaquizobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $this->assertEquals(null, $event->other['submitterid']);
        $this->assertEquals('adaquiz_attempt_abandoned', $event->get_legacy_eventname());
        $legacydata = new stdClass();
        $legacydata->component = 'mod_adaquiz';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $adaquizobj->get_cmid();
        $legacydata->courseid = $adaquizobj->get_courseid();
        $legacydata->adaquizid = $adaquizobj->get_adaquizid();
        $legacydata->submitterid = null; // Should be the user, but PHP Unit complains...
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_started() {
        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();

        // Create another attempt.
        $attempt = adaquiz_create_attempt($adaquizobj, 1, false, time(), false, 2);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        adaquiz_attempt_save_started($adaquizobj, $quba, $attempt);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_started', $event);
        $this->assertEquals('adaquiz_attempts', $event->objecttable);
        $this->assertEquals($attempt->id, $event->objectid);
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertEquals($adaquizobj->get_context(), $event->get_context());
        $this->assertEquals('adaquiz_attempt_started', $event->get_legacy_eventname());
        $this->assertEquals(context_module::instance($adaquizobj->get_cmid()), $event->get_context());
        // Check legacy log data.
        $expected = array($adaquizobj->get_courseid(), 'adaquiz', 'attempt', 'review.php?attempt=' . $attempt->id,
            $adaquizobj->get_adaquizid(), $adaquizobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        // Check legacy event data.
        $legacydata = new stdClass();
        $legacydata->component = 'mod_adaquiz';
        $legacydata->attemptid = $attempt->id;
        $legacydata->timestart = $attempt->timestart;
        $legacydata->timestamp = $attempt->timestart;
        $legacydata->userid = $attempt->userid;
        $legacydata->adaquizid = $adaquizobj->get_adaquizid();
        $legacydata->cmid = $adaquizobj->get_cmid();
        $legacydata->courseid = $adaquizobj->get_courseid();
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the edit page viewed event.
     *
     * There is no external API for updating an adaptive quiz, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_edit_page_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'courseid' => $course->id,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id
            )
        );
        $event = \mod_adaquiz\event\edit_page_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\edit_page_viewed', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'editquestions', 'view.php?id=' . $adaquiz->cmid, $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt deleted event.
     */
    public function test_attempt_deleted() {
        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        adaquiz_delete_attempt($attempt, $adaquizobj->get_adaquiz());
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_deleted', $event);
        $this->assertEquals(context_module::instance($adaquizobj->get_cmid()), $event->get_context());
        $expected = array($adaquizobj->get_courseid(), 'adaquiz', 'delete attempt', 'report.php?id=' . $adaquizobj->get_cmid(),
            $attempt->id, $adaquizobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the report viewed event.
     *
     * There is no external API for viewing reports, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_report_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'context' => $context = context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id,
                'reportname' => 'overview'
            )
        );
        $event = \mod_adaquiz\event\report_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\report_viewed', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'report', 'report.php?id=' . $adaquiz->cmid . '&mode=overview',
            $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt reviewed event.
     *
     * There is no external API for reviewing attempts, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_reviewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id
            )
        );
        $event = \mod_adaquiz\event\attempt_reviewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_reviewed', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'review', 'review.php?attempt=1', $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt summary viewed event.
     *
     * There is no external API for viewing the attempt summary, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_summary_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id
            )
        );
        $event = \mod_adaquiz\event\attempt_summary_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_summary_viewed', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'view summary', 'summary.php?attempt=1', $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override created event.
     *
     * There is no external API for creating a user override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_user_override_created() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id
            )
        );
        $event = \mod_adaquiz\event\user_override_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\user_override_created', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override created event.
     *
     * There is no external API for creating a group override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_group_override_created() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id,
                'groupid' => 2
            )
        );
        $event = \mod_adaquiz\event\group_override_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\group_override_created', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override updated event.
     *
     * There is no external API for updating a user override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_user_override_updated() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id
            )
        );
        $event = \mod_adaquiz\event\user_override_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\user_override_updated', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'edit override', 'overrideedit.php?id=1', $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override updated event.
     *
     * There is no external API for updating a group override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_group_override_updated() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id,
                'groupid' => 2
            )
        );
        $event = \mod_adaquiz\event\group_override_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\group_override_updated', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'edit override', 'overrideedit.php?id=1', $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override deleted event.
     */
    public function test_user_override_deleted() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        // Create an override.
        $override = new stdClass();
        $override->quiz = $adaquiz->id;
        $override->userid = 2;
        $override->id = $DB->insert_record('adaquiz_overrides', $override);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        adaquiz_delete_override($adaquiz, $override->id);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\user_override_deleted', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'delete override', 'overrides.php?cmid=' . $adaquiz->cmid, $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override deleted event.
     */
    public function test_group_override_deleted() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        // Create an override.
        $override = new stdClass();
        $override->quiz = $adaquiz->id;
        $override->groupid = 2;
        $override->id = $DB->insert_record('adaquiz_overrides', $override);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        adaquiz_delete_override($adaquiz, $override->id);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\group_override_deleted', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'delete override', 'overrides.php?cmid=' . $adaquiz->cmid, $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt viewed event.
     *
     * There is no external API for continuing an attempt, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $adaquiz = $this->getDataGenerator()->create_module('adaquiz', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => context_module::instance($adaquiz->cmid),
            'other' => array(
                'adaquizid' => $adaquiz->id
            )
        );
        $event = \mod_adaquiz\event\attempt_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_viewed', $event);
        $this->assertEquals(context_module::instance($adaquiz->cmid), $event->get_context());
        $expected = array($course->id, 'adaquiz', 'continue attempt', 'review.php?attempt=1', $adaquiz->id, $adaquiz->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt previewed event.
     */
    public function test_attempt_preview_started() {
        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();

        // We want to preview this attempt.
        $attempt = adaquiz_create_attempt($adaquizobj, 1, false, time(), false, 2);
        $attempt->preview = 1;

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        adaquiz_attempt_save_started($adaquizobj, $quba, $attempt);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\attempt_preview_started', $event);
        $this->assertEquals(context_module::instance($adaquizobj->get_cmid()), $event->get_context());
        $expected = array($adaquizobj->get_courseid(), 'adaquiz', 'preview', 'view.php?id=' . $adaquizobj->get_cmid(),
            $adaquizobj->get_adaquizid(), $adaquizobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the question manually graded event.
     *
     * There is no external API for manually grading a question, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_question_manually_graded() {
        list($adaquizobj, $quba, $attempt) = $this->prepare_adaquiz_data();

        $params = array(
            'objectid' => 1,
            'courseid' => $adaquizobj->get_courseid(),
            'context' => context_module::instance($adaquizobj->get_cmid()),
            'other' => array(
                'adaquizid' => $adaquizobj->get_adaquizid(),
                'attemptid' => 2,
                'slot' => 3
            )
        );
        $event = \mod_adaquiz\event\question_manually_graded::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_adaquiz\event\question_manually_graded', $event);
        $this->assertEquals(context_module::instance($adaquizobj->get_cmid()), $event->get_context());
        $expected = array($adaquizobj->get_courseid(), 'adaquiz', 'manualgrade', 'comment.php?attempt=2&slot=3',
            $adaquizobj->get_adaquizid(), $adaquizobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }
}
