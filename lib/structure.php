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
 * Defines the \mod_adaquiz\wiris\structure class extending \mod_adaquiz\structure class.
 *
 * @package   mod_adaquiz
 * @copyright 2015 WIRIS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaquiz\wiris;

require_once($CFG->dirroot . '/mod/adaquiz/classes/structure.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Adaptive quiz structure class. Extends original structure class
 *
 */

class structure extends \mod_adaquiz\structure {

    /**
     * Create an instance of this class representing an empty adaptive quiz.
     * @return structure
     */
    public static function create() {
        return new self();
    }
	 /**
     * Create an instance of this class representing the structure of a given adaptive quiz.
     * This method overrides original create_for_adaquiz method.
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
     * Remove a slot from an adaptive quiz and all related nodes and jumps.
     * @param \stdClass $adaquiz the adaptive quiz object.
     * @param int $slotnumber The number of the slot to be deleted.
     */
    public function remove_slot($adaquiz, $slotnumber) {
        global $DB;
        $this->check_can_be_edited();

        $trans = $DB->start_delegated_transaction();

        $slot = $DB->get_record('adaquiz_slots', array('adaquizid' => $adaquiz->id, 'slot' => $slotnumber));
        $maxslot = $DB->get_field_sql('SELECT MAX(slot) FROM {adaquiz_slots} WHERE adaquizid = ?', array($adaquiz->id));
        if (!$slot) {
            return;
        }

        $questions = $this->questions[$slot->questionid];
        $adaquizobj = adaquiz::create($adaquiz->id);
        $nodeid = $adaquizobj->getNodesFromQuestions(array($slot->questionid));
        $adaquizobj->deleteNode(array_shift($nodeid)->id);

        // Call parent remove method.
        parent::remove_slot($adaquiz, $slotnumber);
        $trans->allow_commit();
    }
}
