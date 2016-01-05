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
 * Defines the adaptive quiz repaginate class.
 *
 * @package   mod_adaquiz
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz;
defined('MOODLE_INTERNAL') || die();

/**
 * The repaginate class will rearrange questions in pages.
 *
 * The adaptive quiz setting allows users to write adaptive quizzes with one question per page,
 * n questions per page, or all questions on one page.
 *
 * @copyright 2014 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repaginate {

    /** @var int means join pages. */
    const LINK = 1;
    /** @var int means split pages. */
    const UNLINK = 2;

    /** @var int the id of the adaptive quiz being manipulated. */
    private $adaquizid;
    /** @var array the adaquiz_slots for that adaptive quiz. */
    private $slots;

    /**
     * Constructor.
     * @param int $adaquizid the id of the adaptive quiz being manipulated.
     * @param stdClass[] $slots the adaptive quiz_slots for that adaptive quiz.
     */
    public function __construct($adaquizid = 0, $slots = null) {
        global $DB;
        $this->adaquizid = $adaquizid;
        if (!$this->adaquizid) {
            $this->slots = array();
        }
        if (!$slots) {
            $this->slots = $DB->get_records('adaquiz_slots', array('adaquizid' => $this->adaquizid), 'slot');
        } else {
            $this->slots = $slots;
        }
    }

    /**
     * Repaginate a given slot with the given pagenumber.
     * @param stdClass $slot
     * @param int $newpagenumber
     * @return stdClass
     */
    protected function repaginate_this_slot($slot, $newpagenumber) {
        $newslot = clone($slot);
        $newslot->page = $newpagenumber;
        return $newslot;
    }

    /**
     * Return current slot object.
     * @param array $slots
     * @param int $slotnumber
     * @return stdClass $slot
     */
    protected function get_this_slot($slots, $slotnumber) {
        foreach ($slots as $key => $slot) {
            if ($slot->slot == $slotnumber) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * Return array of slots with slot number as key
     * @param stdClass[] $slots
     * @return stdClass[]
     */
    protected function get_slots_by_slot_number($slots) {
        if (!$slots) {
            return array();
        }
        $newslots = array();
        foreach ($slots as $slot) {
            $newslots[$slot->slot] = $slot;
        }
        return $newslots;
    }

    /**
     * Return array of slots with slot id as key
     * @param stdClass[] $slots
     * @return stdClass[]
     */
    protected function get_slots_by_slotid($slots) {
        if (!$slots) {
            return array();
        }
        $newslots = array();
        foreach ($slots as $slot) {
            $newslots[$slot->id] = $slot;
        }
        return $newslots;
    }

    /**
     * Repaginate, update DB and slots object
     * @param int $nextslotnumber
     * @param int $type repaginate::LINK or repaginate::UNLINK.
     */
    public function repaginate_slots($nextslotnumber, $type) {
        global $DB;
        $this->slots = $DB->get_records('adaquiz_slots', array('adaquizid' => $this->adaquizid), 'slot');
        $nextslot = null;
        $newslots = array();
        foreach ($this->slots as $slot) {
            if ($slot->slot < $nextslotnumber) {
                $newslots[$slot->id] = $slot;
            } else if ($slot->slot == $nextslotnumber) {
                $nextslot = $this->repaginate_next_slot($nextslotnumber, $type);

                // Update DB.
                $DB->update_record('adaquiz_slots', $nextslot, true);

                // Update returning object.
                $newslots[$slot->id] = $nextslot;
            }
        }
        if ($nextslot) {
            $newslots = array_merge($newslots, $this->repaginate_the_rest($this->slots, $nextslotnumber, $type));
            $this->slots = $this->get_slots_by_slotid($newslots);
        }
    }

    /**
     * Repaginate next slot and return the modified slot object
     * @param int $nextslotnumber
     * @param int $type repaginate::LINK or repaginate::UNLINK.
     * @return stdClass|null
     */
    public function repaginate_next_slot($nextslotnumber, $type) {
        $currentslotnumber = $nextslotnumber - 1;
        if (!($currentslotnumber && $nextslotnumber)) {
            return null;
        }
        $currentslot = $this->get_this_slot($this->slots, $currentslotnumber);
        $nextslot = $this->get_this_slot($this->slots, $nextslotnumber);

        if ($type === self::LINK) {
            return $this->repaginate_this_slot($nextslot, $currentslot->page);
        } else if ($type === self::UNLINK) {
            return $this->repaginate_this_slot($nextslot, $nextslot->page + 1);
        }
        return null;
    }

    /**
     * Return the slots with the new pagination, regardless of current pagination.
     * @param stdClass[] $slots the slots to repaginate.
     * @param int $number number of question per page
     * @return stdClass[] the updated slots.
     */
    public function repaginate_n_question_per_page($slots, $number) {
        $slots = $this->get_slots_by_slot_number($slots);
        $newslots = array();
        $count = 0;
        $page = 1;
        foreach ($slots as $key => $slot) {
            for ($page + $count; $page < ($number + $count + 1); $page++) {
                if ($slot->slot >= $page) {
                    $slot->page = $page;
                    $count++;
                }
            }
            $newslots[$slot->id] = $slot;
        }
        return $newslots;
    }

    /**
     * Repaginate the rest.
     * @param stdClass[] $adaquizslots
     * @param int $slotfrom
     * @param int $type
     * @param bool $dbupdate
     * @return stdClass[]
     */
    public function repaginate_the_rest($adaquizslots, $slotfrom, $type, $dbupdate = true) {
        global $DB;
        if (!$adaquizslots) {
            return null;
        }
        $newslots = array();
        foreach ($adaquizslots as $slot) {
            if ($type == self::LINK) {
                if ($slot->slot <= $slotfrom) {
                    continue;
                }
                $slot->page = $slot->page - 1;
            } else if ($type == self::UNLINK) {
                if ($slot->slot <= $slotfrom - 1) {
                    continue;
                }
                $slot->page = $slot->page + 1;
            }
            // Update DB.
            if ($dbupdate) {
                $DB->update_record('adaquiz_slots', $slot);
            }
            $newslots[$slot->id] = $slot;
        }
        return $newslots;
    }
}
