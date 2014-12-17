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

/**
 * Quiz conversion handler
 */
class moodle1_mod_adaquiz_handler extends moodle1_mod_handler {

    /** @var moodle1_file_manager */
    protected $fileman = null;

    /** @var int cmid */
    protected $moduleid = null;

    /**
     * Declare the paths in moodle.xml we are able to convert
     *
     * The method returns list of {@link convert_path} instances.
     * For each path returned, the corresponding conversion method must be
     * defined.
     *
     * Note that the path /MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ does not
     * actually exist in the file. The last element with the module name was
     * appended by the moodle1_converter class.
     *
     * @return array of {@link convert_path} instances
     */
    public function get_paths() {
        return array(
            new convert_path(
                'adaquiz', '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ',
                array(
                    'newfields' => array(
                        'introformat'   => 1,
                    )
                )
            ),
            new convert_path('adaquiz_question_options',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/OPTIONS/REVIEW/QUESTION'),
            new convert_path('adaquiz_adaquiz_options',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/OPTIONS/REVIEW/ADAQUIZ'),
            new convert_path('adaquiz_nodes',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/NODES'),
            new convert_path('adaquiz_node',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/NODES/NODE'),
            new convert_path('adaquiz_node_options',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/NODES/NODE/OPTIONS'),
            new convert_path('adaquiz_jump',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/NODES/NODE/JUMP/CASE'),
            new convert_path('adaquiz_jump_options',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/NODES/NODE/JUMP/CASE/OPTIONS')/*,
            new convert_path('adaquiz_attempts',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/ATTEMPTS'),
            new convert_path('adaquiz_attempt',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/ATTEMPTS/ATTEMPT'),
            new convert_path('adaquiz_nodeattempts',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/ATTEMPTS/ATTEMPT/NODEATTEMPTS'),
            new convert_path('adaquiz_nodeattempt',
                    '/MOODLE_BACKUP/COURSE/MODULES/MOD/ADAQUIZ/ATTEMPTS/ATTEMPT/NODEATTEMPTS/NODEATTEMPT')*/
        );
    }

    /**
     * This is executed every time we have one /MOODLE_BACKUP/COURSE/MODULES/MOD/QUIZ
     * data available
     */
    public function process_adaquiz($data) {
        global $CFG;

        if ($CFG->texteditors !== 'textarea') {
            $data['intro']       = text_to_html($data['intro'], false, false, true);
            $data['introformat'] = FORMAT_HTML;
        }

        // Get the course module id and context id.
        $instanceid     = $data['id'];
        $cminfo         = $this->get_cminfo($instanceid);
        $this->moduleid = $cminfo['id'];
        $contextid      = $this->converter->get_contextid(CONTEXT_MODULE, $this->moduleid);

        // Get a fresh new file manager for this instance.
        $this->fileman = $this->converter->get_file_manager($contextid, 'mod_adaquiz');

        // Convert course files embedded into the intro.
        $this->fileman->filearea = 'intro';
        $this->fileman->itemid   = 0;
        $data['intro'] = moodle1_converter::migrate_referenced_files(
                $data['intro'], $this->fileman);

        // Start writing adaquiz.xml.
        $this->open_xml_writer("activities/adaquiz_{$this->moduleid}/adaquiz.xml");
        $this->xmlwriter->begin_tag('activity', array('id' => $instanceid,
                'moduleid' => $this->moduleid, 'modulename' => 'adaquiz',
                'contextid' => $contextid));
        $this->xmlwriter->begin_tag('adaquiz', array('id' => $instanceid));

        foreach ($data as $field => $value) {
            if ($field <> 'id' && $field <> 'modtype') {
                $this->xmlwriter->full_tag($field, $value);
            }
        }

        return $data;
    }

    public function process_adaquiz_question_options($data){
            $options = array();

            if (isset($data['useranswer']) && isset($data['correctanswer']) && isset($data['feedback']) && isset($data['score'])){
                $options->useranswer = $data['useranswer'];
                $options->correctanswer = $data['correctanswer'];
                $options->feedback = $data['feedback'];
                $options->score = $data['score'];

                $this->questionsoptions = $options;
            }
    }


    public function process_adaquiz_adaquiz_options($data){
            $options = array();

            if (isset($data['useranswer']) && isset($data['correctanswer']) && isset($data['feedback']) && isset($data['score'])){
                $options->useranswer = $data['useranswer'];
                $options->correctanswer = $data['correctanswer'];
                $options->feedback = $data['feedback'];
                $options->score = $data['score'];

                $this->adaquizoptions = $options;
            }
    }

    public function on_adaquiz_nodes_start() {
        $options = array();

        if (isset($this->adaquizoptions)){
            foreach($this->adaquizoptions as $key => $value){
                $options['review']['adaquiz'][$key] = $value;
            }
            unset($this->adaquizoptions);
        }else{
            $options['review']['adaquiz']['useranswer'] = '1';
            $options['review']['adaquiz']['correctanswer'] = '1';
            $options['review']['adaquiz']['feedback'] = '1';
            $options['review']['adaquiz']['score'] = '1';
        }
        if (isset($this->questionsoptions)){
            foreach($this->questionsoptions as $key => $value){
                $options['review']['question'][$key] = $value;
            }
            unset($this->questionsoptions);
        }else{
            $options['review']['question']['useranswer'] = '1';
            $options['review']['question']['correctanswer'] = '0';
            $options['review']['question']['feedback'] = '1';
            $options['review']['question']['score'] = '1';
        }

        $options = serialize($options);
        $this->xmlwriter->full_tag('options', $options);

        $this->xmlwriter->begin_tag('nodes');
    }

    public function on_adaquiz_nodes_end() {
        $this->xmlwriter->end_tag('nodes');

        //Add all jumps
        $this->xmlwriter->begin_tag('jumps');

        foreach($this->actualnodeid as $actualnodeid){
            $this->xmlwriter->begin_tag('nodeid', array('id' => $actualnodeid));
            foreach ($this->actualjumps as $actualjump){
                if ($actualjump['nodefrom'] == $actualnodeid){
                    $this->xmlwriter->begin_tag('jump', array('id' => $actualjump['id']));
                        foreach($actualjump as $field => $value){
                            if ($field <> 'id'){
                                $this->xmlwriter->full_tag($field, $value);
                            }
                        }
                    $this->xmlwriter->end_tag('jump');
                }
            }
            $this->xmlwriter->end_tag('nodeid');
        }

        $this->xmlwriter->end_tag('jumps');
    }

    public function process_adaquiz_node($data){
        $this->xmlwriter->begin_tag('node', array('id' => $data['id']));
        foreach ($data as $field => $value) {
            if ($field <> 'id') {
                $this->xmlwriter->full_tag($field, $value);
            }else{
                $this->actualnodeid[] = $value;
            }
        }
    }

    public function on_adaquiz_node_end() {
        $options = array();
        if (isset($this->commonrandomseedupdated)){
            $options['commonrandomseed'] = $this->commonrandomseedupdated;
            unset($this->commonrandomseedupdated);
        }else{
            $options['commonrandomseed'] = false;
        }
        if (isset($this->letstudentdecidejump)){
            $options['letstudentdecidejump'] = $this->letstudentdecidejump;
            unset($this->letstudentdecidejump);
        }else{
            $options['letstudentdecidejump'] = false;
        }

        $options = serialize($options);
        $this->xmlwriter->full_tag('options', $options);
        $this->xmlwriter->end_tag('node');
    }

    public function process_adaquiz_node_options($data){
        if (isset($data['commonrandomseed'])){
            $this->commonrandomseedupdated = $data['commonrandomseed'];
        }
        if (isset($data['letstudentdecidejump'])){
            $this->letstudentdecidejump = $data['letstudentdecidejump'];
        }
    }

    public function on_adaquiz_jump_end() {
        $options = array();
        if (isset($this->cmp) && isset($this->value)){
            $options['cmp'] = $this->cmp;
            $options['value'] = $this->value;
            unset($this->cmp);
            unset($this->value);
        }
        $options = serialize($options);

        $this->actualprocessingjumps['options'] = $options;
        $this->actualjumps[] = $this->actualprocessingjumps;
        unset($this->actualprocessingjumps);
    }

    public function process_adaquiz_jump($data){
        $this->actualprocessingjumps = $data;
    }

    public function process_adaquiz_jump_options($data){
        if (isset($data['cmp']) && isset($data['value'])){
            $this->cmp = $data['cmp'];
            $this->value = $data['value'];
        }
    }

    public function on_adaquiz_attempts_start() {
        $this->xmlwriter->begin_tag('attempts');
    }

    public function on_adaquiz_attempts_end() {
        $this->xmlwriter->end_tag('attempts');
    }

    public function process_adaquiz_attempt($data){
        $this->xmlwriter->begin_tag('attempt', array('id' => $data['id']));
        foreach ($data as $field => $value) {
            if ($field <> 'id') {
                $this->xmlwriter->full_tag($field, $value);
            }
        }
        $this->xmlwriter->end_tag('attempt');
    }

    public function on_adaquiz_nodeattempts_start() {
        $this->xmlwriter->begin_tag('node_attempts');
    }

    public function on_adaquiz_nodeattempts_end() {
        $this->xmlwriter->end_tag('node_attempts');
    }

    public function process_adaquiz_nodeattempt($data) {
        $this->xmlwriter->begin_tag('node_attempt', array('id' => $data['id']));
        foreach ($data as $field => $value) {
            if ($field <> 'id') {
                $this->xmlwriter->full_tag($field, $value);
            }
        }
        $this->xmlwriter->end_tag('node_attempt');
    }


    /**
     * This is executed when we reach the closing </MOD> tag of our 'adaquiz' path
     */
    public function on_adaquiz_end() {
        // Finish writing adaquiz.xml.
        $this->xmlwriter->end_tag('adaquiz');
        $this->xmlwriter->end_tag('activity');
        $this->close_xml_writer();
    }
}
