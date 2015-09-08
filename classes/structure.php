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
 * Defines the \mod_adaquiz\structure class.
 *
 * @package   mod_adaquiz
 * @copyright 2013 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz;
defined('MOODLE_INTERNAL') || die();

/**
 * Adaptive quiz structure class.
 *
 * The structure of the adaptive quiz. That is, which questions it is built up
 * from. This is used on the Edit adaptive quiz page (edit.php) and also when
 * starting an attempt at the adaptive quiz (startattempt.php). Once an attempt
 * has been started, then the attempt holds the specific set of questions
 * that that student should answer, and we no longer use this class.
 *
 * @copyright 2014 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure {
    /** @var \quiz the adaptive quiz this is the structure of. */
    protected $adaquizobj = null;

    /**
     * @var \stdClass[] the questions in this adaptive quiz. Contains the row from the questions
     * table, with the data from the adaquiz_slots table added, and also question_categories.contextid.
     */
    protected $questions = array();

    /** @var \stdClass[] adaquiz_slots.id => the adaquiz_slots rows for this adaptive quiz, agumented by sectionid. */
    protected $slots = array();

    /** @var \stdClass[] adaquiz_slots.slot => the adaquiz_slots rows for this adaptive quiz, agumented by sectionid. */
    protected $slotsinorder = array();

    /**
     * @var \stdClass[] currently a dummy. Holds data that will match the
     * adaquiz_sections, once it exists.
     */
    protected $sections = array();

    /** @var bool caches the results of can_be_edited. */
    protected $canbeedited = null;

    /**
     * Create an instance of this class representing an empty adaptive quiz.
     * @return structure
     */
    public static function create() {
        return new self();
    }

    /**
     * Create an instance of this class representing the structure of a given adaptive quiz.
     * @param \adaquiz $adaquizobj the adaptive quiz.
     * @return structure
     */
    public static function create_for_adaquiz($adaquizobj) {
        $structure = self::create();
        $structure->adaquizobj = $adaquizobj;
        $structure->populate_structure($adaquizobj->get_adaquiz());
        return $structure;
    }

    /**
     * Whether there are any questions in the adaptive quiz.
     * @return bool true if there is at least one question in the adaptive quiz.
     */
    public function has_questions() {
        return !empty($this->questions);
    }

    /**
     * Get the number of questions in the adaptive quiz.
     * @return int the number of questions in the adaptive quiz.
     */
    public function get_question_count() {
        return count($this->questions);
    }

    /**
     * Get the information about the question with this id.
     * @param int $questionid The question id.
     * @return \stdClass the data from the questions table, augmented with
     * question_category.contextid, and the adaquiz_slots data for the question in this adaptive quiz.
     */
    public function get_question_by_id($questionid) {
        return $this->questions[$questionid];
    }

    /**
     * Get the information about the question in a given slot.
     * @param int $slotnumber the index of the slot in question.
     * @return \stdClass the data from the questions table, augmented with
     * question_category.contextid, and the adaquiz_slots data for the question in this adaptive quiz.
     */
    public function get_question_in_slot($slotnumber) {
        return $this->questions[$this->slotsinorder[$slotnumber]->questionid];
    }

    /**
     * Get the course module id of the adaptive quiz.
     * @return int the course_modules.id for the adaptive quiz.
     */
    public function get_cmid() {
        return $this->adaquizobj->get_cmid();
    }

    /**
     * Get id of the adaptive quiz.
     * @return int the adaquiz.id for the adaptive quiz.
     */
    public function get_adaquizid() {
        return $this->adaquizobj->get_adaquizid();
    }

    /**
     * Get the adaquiz object.
     * @return \stdClass the adaptive quiz settings row from the database.
     */
    public function get_adaquiz() {
        return $this->adaquizobj->get_adaquiz();
    }

    /**
     * Whether the question in the adaptive quiz are shuffled for each attempt.
     * @return bool true if the questions are shuffled.
     */
    public function is_shuffled() {
        return $this->adaquizobj->get_adaquiz()->shufflequestions;
    }

    /**
     * Adaptive quizzes can only be repaginated if they have not been attempted, the
     * questions are not shuffled, and there are two or more questions.
     * @return bool whether this adaptive quiz can be repaginated.
     */
    public function can_be_repaginated() {
        return !$this->is_shuffled() && $this->can_be_edited()
                && $this->get_question_count() >= 2;
    }

    /**
     * Adaptive quizzes can only be edited if they have not been attempted.
     * @return bool whether the adaptive quiz can be edited.
     */
    public function can_be_edited() {
        if ($this->canbeedited === null) {
            $this->canbeedited = !adaquiz_has_attempts($this->adaquizobj->get_adaquizid());
        }
        return $this->canbeedited;
    }

    /**
     * This adaptive quiz can only be edited if they have not been attempted.
     * Throw an exception if this is not the case.
     */
    public function check_can_be_edited() {
        if (!$this->can_be_edited()) {
            $reportlink = adaquiz_attempt_summary_link_to_reports($this->get_adaquiz(),
                    $this->adaquizobj->get_cm(), $this->adaquizobj->get_context());
            throw new \moodle_exception('cannoteditafterattempts', 'adaquiz',
                    new \moodle_url('/mod/adaquiz/edit.php', array('cmid' => $this->get_cmid())), $reportlink);
        }
    }

    /**
     * How many questions are allowed per page in the adaptive quiz.
     * This setting controls how frequently extra page-breaks should be inserted
     * automatically when questions are added to the adaptive quiz.
     * @return int the number of questions that should be on each page of the
     * adaptive quiz by default.
     */
    public function get_questions_per_page() {
        return $this->adaquizobj->get_adaquiz()->questionsperpage;
    }

    /**
     * Get adaptive quiz slots.
     * @return \stdClass[] the slots in this adaptive quiz.
     */
    public function get_slots() {
        return $this->slots;
    }

    /**
     * Is this slot the first one on its page?
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the first one on its page.
     */
    public function is_first_slot_on_page($slotnumber) {
        if ($slotnumber == 1) {
            return true;
        }
        return $this->slotsinorder[$slotnumber]->page != $this->slotsinorder[$slotnumber - 1]->page;
    }

    /**
     * Is this slot the last one on its page?
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the last one on its page.
     */
    public function is_last_slot_on_page($slotnumber) {
        if (!isset($this->slotsinorder[$slotnumber + 1])) {
            return true;
        }
        return $this->slotsinorder[$slotnumber]->page != $this->slotsinorder[$slotnumber + 1]->page;
    }

    /**
     * Is this slot the last one in the adaptive quiz?
     * @param int $slotnumber the index of the slot in question.
     * @return bool whether this slot the last one in the adaptive quiz.
     */
    public function is_last_slot_in_adaquiz($slotnumber) {
        end($this->slotsinorder);
        return $slotnumber == key($this->slotsinorder);
    }

    /**
     * Get the final slot in the adaptive quiz.
     * @return \stdClass the adaquiz_slots for for the final slot in the adaptive quiz.
     */
    public function get_last_slot() {
        return end($this->slotsinorder);
    }

    /**
     * Get a slot by it's id. Throws an exception if it is missing.
     * @param int $slotid the slot id.
     * @return \stdClass the requested adaquiz_slots row.
     */
    public function get_slot_by_id($slotid) {
        if (!array_key_exists($slotid, $this->slots)) {
            throw new \coding_exception('The \'slotid\' could not be found.');
        }
        return $this->slots[$slotid];
    }

    /**
     * Get all the questions in a section of the adaptive quiz.
     * @param int $sectionid the section id.
     * @return \stdClass[] of question/slot objects.
     */
    public function get_questions_in_section($sectionid) {
        $questions = array();
        foreach ($this->slotsinorder as $slot) {
            if ($slot->sectionid == $sectionid) {
                $questions[] = $this->questions[$slot->questionid];
            }
        }
        return $questions;
    }

    /**
     * Get all the sections of the adaptive quiz.
     * @return \stdClass[] the sections in this adaptive quiz.
     */
    public function get_adaquiz_sections() {
        return $this->sections;
    }

    /**
     * Get any warnings to show at the top of the edit page.
     * @return string[] array of strings.
     */
    public function get_edit_page_warnings() {
        $warnings = array();

        if (adaquiz_has_attempts($this->adaquizobj->get_adaquizid())) {
            $reviewlink = adaquiz_attempt_summary_link_to_reports($this->adaquizobj->get_adaquiz(),
                    $this->adaquizobj->get_cm(), $this->adaquizobj->get_context());
            $warnings[] = get_string('cannoteditafterattempts', 'adaquiz', $reviewlink);
        }

        if ($this->is_shuffled()) {
            $updateurl = new \moodle_url('/course/mod.php',
                    array('return' => 'true', 'update' => $this->adaquizobj->get_cmid(), 'sesskey' => sesskey()));
            $updatelink = '<a href="'.$updateurl->out().'">' . get_string('updatethis', '',
                    get_string('modulename', 'adaquiz')) . '</a>';
            $warnings[] = get_string('shufflequestionsselected', 'adaquiz', $updatelink);
        }

        return $warnings;
    }

    /**
     * Get the date information about the current state of the adaptive quiz.
     * @return string[] array of two strings. First a short summary, then a longer
     * explanation of the current state, e.g. for a tool-tip.
     */
    public function get_dates_summary() {
        $timenow = time();
        $adaquiz = $this->adaquizobj->get_adaquiz();

        // Exact open and close dates for the tool-tip.
        $dates = array();
        if ($adaquiz->timeopen > 0) {
            if ($timenow > $adaquiz->timeopen) {
                $dates[] = get_string('quizopenedon', 'adaquiz', userdate($adaquiz->timeopen));
            } else {
                $dates[] = get_string('quizwillopen', 'adaquiz', userdate($adaquiz->timeopen));
            }
        }
        if ($adaquiz->timeclose > 0) {
            if ($timenow > $adaquiz->timeclose) {
                $dates[] = get_string('quizclosed', 'adaquiz', userdate($adaquiz->timeclose));
            } else {
                $dates[] = get_string('quizcloseson', 'adaquiz', userdate($adaquiz->timeclose));
            }
        }
        if (empty($dates)) {
            $dates[] = get_string('alwaysavailable', 'adaquiz');
        }
        $explanation = implode(', ', $dates);

        // Brief summary on the page.
        if ($timenow < $adaquiz->timeopen) {
            $currentstatus = get_string('quizisclosedwillopen', 'adaquiz',
                    userdate($adaquiz->timeopen, get_string('strftimedatetimeshort', 'langconfig')));
        } else if ($adaquiz->timeclose && $timenow <= $adaquiz->timeclose) {
            $currentstatus = get_string('quizisopenwillclose', 'adaquiz',
                    userdate($adaquiz->timeclose, get_string('strftimedatetimeshort', 'langconfig')));
        } else if ($adaquiz->timeclose && $timenow > $adaquiz->timeclose) {
            $currentstatus = get_string('quizisclosed', 'adaquiz');
        } else {
            $currentstatus = get_string('quizisopen', 'adaquiz');
        }

        return array($currentstatus, $explanation);
    }

    /**
     * Set up this class with the structure for a given adaptive quiz.
     * @param \stdClass $adaquiz the adaptive quiz settings.
     */
    public function populate_structure($adaquiz) {
        global $DB;

        $slots = $DB->get_records_sql("
                SELECT slot.id AS slotid, slot.slot, slot.questionid, slot.page, slot.maxmark,
                       q.*, qc.contextid
                  FROM {adaquiz_slots} slot
                  LEFT JOIN {question} q ON q.id = slot.questionid
                  LEFT JOIN {question_categories} qc ON qc.id = q.category
                 WHERE slot.adaquizid = ?
              ORDER BY slot.slot", array($adaquiz->id));

        $slots = $this->populate_missing_questions($slots);

        $this->questions = array();
        $this->slots = array();
        $this->slotsinorder = array();
        foreach ($slots as $slotdata) {
            $this->questions[$slotdata->questionid] = $slotdata;

            $slot = new \stdClass();
            $slot->id = $slotdata->slotid;
            $slot->slot = $slotdata->slot;
            $slot->adaquizid = $adaquiz->id;
            $slot->page = $slotdata->page;
            $slot->questionid = $slotdata->questionid;
            $slot->maxmark = $slotdata->maxmark;

            $this->slots[$slot->id] = $slot;
            $this->slotsinorder[$slot->slot] = $slot;
        }

        $section = new \stdClass();
        $section->id = 1;
        $section->adaquizid = $adaquiz->id;
        $section->heading = '';
        $section->firstslot = 1;
        $section->shuffle = false;
        $this->sections = array(1 => $section);

        $this->populate_slots_with_sectionids();
        $this->populate_question_numbers();
    }

    /**
     * Used by populate. Make up fake data for any missing questions.
     * @param \stdClass[] $slots the data about the slots and questions in the adaptive quiz.
     * @return \stdClass[] updated $slots array.
     */
    protected function populate_missing_questions($slots) {
        // Address missing question types.
        foreach ($slots as $slot) {
            if ($slot->qtype === null) {
                // If the questiontype is missing change the question type.
                $slot->id = $slot->questionid;
                $slot->category = 0;
                $slot->qtype = 'missingtype';
                $slot->name = get_string('missingquestion', 'adaquiz');
                $slot->slot = $slot->slot;
                $slot->maxmark = 0;
                $slot->questiontext = ' ';
                $slot->questiontextformat = FORMAT_HTML;
                $slot->length = 1;

            } else if (!\question_bank::qtype_exists($slot->qtype)) {
                $slot->qtype = 'missingtype';
            }
        }

        return $slots;
    }

    /**
     * Fill in the section ids for each slot.
     */
    public function populate_slots_with_sectionids() {
        $nextsection = reset($this->sections);
        foreach ($this->slotsinorder as $slot) {
            if ($slot->slot == $nextsection->firstslot) {
                $currentsectionid = $nextsection->id;
                $nextsection = next($this->sections);
                if (!$nextsection) {
                    $nextsection = new \stdClass();
                    $nextsection->firstslot = -1;
                }
            }

            $slot->sectionid = $currentsectionid;
        }
    }

    /**
     * Number the questions.
     */
    protected function populate_question_numbers() {
        $number = 1;
        foreach ($this->slots as $slot) {
            $question = $this->questions[$slot->questionid];
            if ($question->length == 0) {
                $question->displayednumber = get_string('infoshort', 'adaquiz');
            } else {
                $question->displayednumber = $number;
                $number += 1;
            }
        }
    }

    /**
     * Move a slot from its current location to a new location.
     *
     * After callig this method, this class will be in an invalid state, and
     * should be discarded if you want to manipulate the structure further.
     *
     * @param int $idmove id of slot to be moved
     * @param int $idbefore id of slot to come before slot being moved
     * @param int $page new page number of slot being moved
     * @return void
     */
    public function move_slot($idmove, $idbefore, $page) {
        global $DB;

        $this->check_can_be_edited();

        $movingslot = $this->slots[$idmove];
        if (empty($movingslot)) {
            throw new moodle_exception('Bad slot ID ' . $idmove);
        }
        $movingslotnumber = (int) $movingslot->slot;

        // Empty target slot means move slot to first.
        if (empty($idbefore)) {
            $targetslotnumber = 0;
        } else {
            $targetslotnumber = (int) $this->slots[$idbefore]->slot;
        }

        // Work out how things are being moved.
        $slotreorder = array();
        if ($targetslotnumber > $movingslotnumber) {
            $slotreorder[$movingslotnumber] = $targetslotnumber;
            for ($i = $movingslotnumber; $i < $targetslotnumber; $i++) {
                $slotreorder[$i + 1] = $i;
            }
        } else if ($targetslotnumber < $movingslotnumber - 1) {
            $slotreorder[$movingslotnumber] = $targetslotnumber + 1;
            for ($i = $targetslotnumber + 1; $i < $movingslotnumber; $i++) {
                $slotreorder[$i] = $i + 1;
            }
        }

        $trans = $DB->start_delegated_transaction();

        // Slot has moved record new order.
        if ($slotreorder) {
            update_field_with_unique_index('adaquiz_slots', 'slot', $slotreorder,
                    array('adaquizid' => $this->get_adaquizid()));
        }

        // Page has changed. Record it.
        if (!$page) {
            $page = 1;
        }
        if ($movingslot->page != $page) {
            $DB->set_field('adaquiz_slots', 'page', $page,
                    array('id' => $movingslot->id));
        }

        $emptypages = $DB->get_fieldset_sql("
                SELECT DISTINCT page - 1
                  FROM {adaquiz_slots} slot
                 WHERE adaquizid = ?
                   AND page > 1
                   AND NOT EXISTS (SELECT 1 FROM {adaquiz_slots} WHERE adaquizid = ? AND page = slot.page - 1)
              ORDER BY page - 1 DESC
                ", array($this->get_adaquizid(), $this->get_adaquizid()));

        foreach ($emptypages as $page) {
            $DB->execute("
                    UPDATE {adaquiz_slots}
                       SET page = page - 1
                     WHERE adaquizid = ?
                       AND page > ?
                    ", array($this->get_adaquizid(), $page));
        }

        $trans->allow_commit();
    }

    /**
     * Refresh page numbering of adaptive quiz slots.
     * @param \stdClass $adaquiz the adaptive quiz object.
     * @param \stdClass[] $slots (optional) array of slot objects.
     * @return \stdClass[] array of slot objects.
     */
    public function refresh_page_numbers($adaquiz, $slots=array()) {
        global $DB;
        // Get slots ordered by page then slot.
        if (!count($slots)) {
            $slots = $DB->get_records('adaquiz_slots', array('adaquizid' => $adaquiz->id), 'slot, page');
        }

        // Loop slots. Start Page number at 1 and increment as required.
        $pagenumbers = array('new' => 0, 'old' => 0);

        foreach ($slots as $slot) {
            if ($slot->page !== $pagenumbers['old']) {
                $pagenumbers['old'] = $slot->page;
                ++$pagenumbers['new'];
            }

            if ($pagenumbers['new'] == $slot->page) {
                continue;
            }
            $slot->page = $pagenumbers['new'];
        }

        return $slots;
    }

    /**
     * Refresh page numbering of adaptive quiz slots and save to the database.
     * @param \stdClass $adaquiz the adaptive quiz object.
     * @return \stdClass[] array of slot objects.
     */
    public function refresh_page_numbers_and_update_db($adaquiz) {
        global $DB;
        $this->check_can_be_edited();

        $slots = $this->refresh_page_numbers($adaquiz);

        // Record new page order.
        foreach ($slots as $slot) {
            $DB->set_field('adaquiz_slots', 'page', $slot->page,
                    array('id' => $slot->id));
        }

        return $slots;
    }

    /**
     * Remove a slot from an adaptive quiz
     * @param \stdClass $adaquiz the adaptive quiz object.
     * @param int $slotnumber The number of the slot to be deleted.
     */
    public function remove_slot($adaquiz, $slotnumber) {
        global $DB;

        $this->check_can_be_edited();

        $slot = $DB->get_record('adaquiz_slots', array('adaquizid' => $adaquiz->id, 'slot' => $slotnumber));
        $maxslot = $DB->get_field_sql('SELECT MAX(slot) FROM {adaquiz_slots} WHERE adaquizid = ?', array($adaquiz->id));
        if (!$slot) {
            return;
        }

        $trans = $DB->start_delegated_transaction();
        $DB->delete_records('adaquiz_slots', array('id' => $slot->id));
        for ($i = $slot->slot + 1; $i <= $maxslot; $i++) {
            $DB->set_field('adaquiz_slots', 'slot', $i - 1,
                    array('adaquizid' => $adaquiz->id, 'slot' => $i));
        }

        $qtype = $DB->get_field('question', 'qtype', array('id' => $slot->questionid));
        if ($qtype === 'random') {
            // This function automatically checks if the question is in use, and won't delete if it is.
            question_delete_question($slot->questionid);
        }

        unset($this->questions[$slot->questionid]);

        $this->refresh_page_numbers_and_update_db($adaquiz);

        $trans->allow_commit();
    }

    /**
     * Change the max mark for a slot.
     *
     * Saves changes to the question grades in the adaquiz_slots table and any
     * corresponding question_attempts.
     * It does not update 'sumgrades' in the adaptive quiz table.
     *
     * @param \stdClass $slot row from the adaquiz_slots table.
     * @param float $maxmark the new maxmark.
     * @return bool true if the new grade is different from the old one.
     */
    public function update_slot_maxmark($slot, $maxmark) {
        global $DB;

        if (abs($maxmark - $slot->maxmark) < 1e-7) {
            // Grade has not changed. Nothing to do.
            return false;
        }

        $trans = $DB->start_delegated_transaction();
        $slot->maxmark = $maxmark;
        $DB->update_record('adaquiz_slots', $slot);
        \question_engine::set_max_mark_in_attempts(new \qubaids_for_adaquiz($slot->adaquizid),
                $slot->slot, $maxmark);
        $trans->allow_commit();

        return true;
    }

    /**
     * Add/Remove a pagebreak.
     *
     * Saves changes to the slot page relationship in the adaquiz_slots table and reorders the paging
     * for subsequent slots.
     *
     * @param \stdClass $adaquiz the adaptive quiz object.
     * @param int $slotid id of slot.
     * @param int $type repaginate::LINK or repaginate::UNLINK.
     * @return \stdClass[] array of slot objects.
     */
    public function update_page_break($adaquiz, $slotid, $type) {
        global $DB;

        $this->check_can_be_edited();

        $adaquizslots = $DB->get_records('adaquiz_slots', array('adaquizid' => $adaquiz->id), 'slot');
        $repaginate = new \mod_adaquiz\repaginate($adaquiz->id, $adaquizslots);
        $repaginate->repaginate_slots($adaquizslots[$slotid]->slot, $type);
        $slots = $this->refresh_page_numbers_and_update_db($adaquiz);

        return $slots;
    }
}
