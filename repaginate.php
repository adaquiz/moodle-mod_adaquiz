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
 * Rest endpoint for ajax editing for paging operations on the adaptive quiz structure.
 *
 * @package   mod_adaquiz
 * @copyright 2015 Maths for More S.L.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaquiz/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$adaquizid = required_param('adaquizid', PARAM_INT);
$slotnumber = required_param('slot', PARAM_INT);
$repagtype = required_param('repag', PARAM_INT);

require_sesskey();
$adaquizobj = adaquiz::create($adaquizid);
require_login($adaquizobj->get_course(), false, $adaquizobj->get_cm());
require_capability('mod/adaquiz:manage', $adaquizobj->get_context());
if (adaquiz_has_attempts($adaquizid)) {
    $reportlink = adaquiz_attempt_summary_link_to_reports($adaquizobj->get_adaquiz(),
                    $adaquizobj->get_cm(), $adaquizobj->get_context());
    throw new \moodle_exception('cannoteditafterattempts', 'adaquiz',
            new moodle_url('/mod/adaquiz/edit.php', array('cmid' => $cmid)), $reportlink);
}

$slotnumber++;
$repage = new \mod_adaquiz\repaginate($adaquizid);
$repage->repaginate_slots($slotnumber, $repagtype);

$structure = $adaquizobj->get_structure();
$slots = $structure->refresh_page_numbers_and_update_db($structure->get_adaquiz());

redirect(new moodle_url('edit.php', array('cmid' => $adaquizobj->get_cmid())));
