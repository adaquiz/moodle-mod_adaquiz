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
 * The renderer for the adaquiz module.
 */
class mod_adaquiz_renderer extends plugin_renderer_base {
    
    /**
     * Returns the same as {@link quiz_num_attempt_summary()} but wrapped in a link
     * to the quiz reports.
     *
     * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
     * @param object $cm the cm object. Only $cm->course, $cm->groupmode and $cm->groupingid
     * fields are used at the moment.
     * @param object $context the quiz context.
     * @param bool $returnzero if false (default), when no attempts have been made '' is returned
     * instead of 'Attempts: 0'.
     * @param int $currentgroup if there is a concept of current group where this method is being
     * called
     *         (e.g. a report) pass it in here. Default 0 which means no current group.
     * @return string HTML fragment for the link.
     */
    public function adaquiz_attempt_summary_link_to_reports($quiz, $cm, $context,
                                                          $returnzero = false, $currentgroup = 0) {
        global $CFG;
        $summary = adaquiz_num_attempt_summary($quiz, $cm, $returnzero, $currentgroup);
        if (!$summary) {
            return '';
        }

        require_once($CFG->dirroot . '/mod/adaquiz/report/reportlib.php');
        $url = new moodle_url('/mod/adaquiz/report.php', array(
                'id' => $cm->id, 'mode' => quiz_report_default_report($context)));
        return html_writer::link($url, $summary);
    }    
    
    public function view_page($course, $quiz, $cm, $context, $viewobj) {
        $output = '';
        $output .= $this->view_information($quiz, $cm, $context, $viewobj->infomessages);
        $output .= $this->view_table($quiz, $context, $viewobj, $cm->id);
        $output .= $this->view_result_info($quiz, $context, $cm, $viewobj);
        $output .= $this->box($this->view_page_buttons($viewobj), 'adaquizattempt');
        return $output;
    }    

    public function view_page_buttons(mod_adaquiz_view_object $viewobj) {
        $output = '';

        if (!$viewobj->quizhasquestions) {
            $output .= $this->no_questions_message($viewobj->canedit, $viewobj->editurl);
        }

        if ($viewobj->buttontext) {
            $output .= $this->start_attempt_button($viewobj->buttontext,
                    $viewobj->startattempturl, $viewobj->startattemptwarning,
                    $viewobj->popuprequired, $viewobj->popupoptions);

        } else if ($viewobj->buttontext === '') {
            // We should show a 'back to the course' button.
            $output .= $this->single_button($viewobj->backtocourseurl,
                    get_string('backtocourse', 'adaquiz'), 'get',
                    array('class' => 'continuebutton'));
        }

        return $output;
    }    

    /**
     * Generates the view attempt button
     *
     * @param int $course The course ID
     * @param array $quiz Array containging quiz date
     * @param int $cm The Course Module ID
     * @param int $context The page Context ID
     * @param mod_quiz_view_object $viewobj
     * @param string $buttontext
     */
    public function start_attempt_button($buttontext, moodle_url $url,
            $startattemptwarning, $popuprequired, $popupoptions) {

        $button = new single_button($url, $buttontext);
        $button->class .= ' adaquizstartbuttondiv';

        $warning = '';
        if ($popuprequired) {
            $this->page->requires->js_module(adaquiz_get_js_module());
            $this->page->requires->js('/mod/quiz/module.js');
            $popupaction = new popup_action('click', $url, 'adaquizpopup', $popupoptions);

            $button->class .= ' adaquizsecuremoderequired';
            $button->add_action(new component_action('click',
                    'M.mod_quiz.secure_window.start_attempt_action', array(
                        'url' => $url->out(false),
                        'windowname' => 'quizpopup',
                        'options' => $popupaction->get_js_options(),
                        'fullscreen' => true,
                        'startattemptwarning' => $startattemptwarning,
                    )));

            $warning = html_writer::tag('noscript', $this->heading(get_string('noscript', 'adaquiz')));

        } else if ($startattemptwarning) {
            $button->add_action(new confirm_action($startattemptwarning, null,
                    get_string('startattempt', 'adaquiz')));
        }

        return $this->render($button) . $warning;
    }    
    
    public function no_questions_message($canedit, $editurl) {
        $output = '';
        $output .= $this->notification(get_string('noquestions', 'adaquiz'));
        if ($canedit) {
            $output .= $this->single_button($editurl, get_string('editquiz', 'adaquiz'), 'get');
        }

        return $output;
    }    

    /**
     * Renders the review question pop-up.
     *
     * @param quiz_attempt $attemptobj an instance of adaquiz_attempt.
     * @param int $slot which question to display.
     * @param int $seq which step of the question attempt to show. null = latest.
     * @param mod_quiz_display_options $displayoptions instance of mod_quiz_display_options.
     * @param array $summarydata contains all table data
     * @return $output containing html data.
     */
    public function review_question_page(adaquiz_attempt $attemptobj, $slot, $seq,
            mod_quiz_display_options $displayoptions, $summarydata) {

        $output = '';
        $output .= $this->header();
        $output .= $this->review_summary_table($summarydata, 0);

        if (!is_null($seq)) {
            $output .= $attemptobj->render_question_at_step($slot, $seq, true);
        } else {
            $output .= $attemptobj->render_question($slot, true);
        }

        $output .= $this->close_window_button();
        $output .= $this->footer();
        return $output;
    }    
    
    public function view_information($quiz, $cm, $context, $messages) {
        global $CFG;

        $output = '';
        // Print quiz name and description.
        $output .= $this->heading(format_string($quiz->name));
        if (trim(strip_tags($quiz->intro))) {
            $output .= $this->box(format_module_intro('adaquiz', $quiz, $cm->id), 'generalbox',
                    'intro');
        }

        // Show number of attempts summary to those who can view reports.
        if (has_capability('mod/adaquiz:viewreports', $context)) {
            if ($strattemptnum = $this->adaquiz_attempt_summary_link_to_reports($quiz, $cm,
                    $context)) {
                $output .= html_writer::tag('div', $strattemptnum,
                        array('class' => 'quizattemptcounts'));
            }
        }        
        
        return $output;
    }    
    

    public function access_messages($messages) {
        $output = '';
        foreach ($messages as $message) {
            $output .= html_writer::tag('p', $message) . "\n";
        }
        return $output;
    }    
    
    public function view_table($quiz, $context, $viewobj, $cmid) {
        if (!$viewobj->attempts) {
            return '';
        }

        // Prepare table header.
        $table = new html_table();
        $table->attributes['class'] = 'generaltable adaquizattemptsummary';
        $table->head = array();
        $table->align = array();
        $table->size = array();
        if ($viewobj->attemptcolumn) {
            $table->head[] = get_string('attemptnumber', 'adaquiz');
            $table->align[] = 'center';
            $table->size[] = '';
        }
        $table->head[] = get_string('attemptstate', 'adaquiz');
        $table->align[] = 'left';
        $table->size[] = '';
        if ($viewobj->markcolumn) {
            $table->head[] = get_string('marks', 'adaquiz');
            $table->align[] = 'center';
            $table->size[] = '';
        }
        if ($viewobj->gradecolumn) {
            $table->head[] = get_string('grade') . ' / ' . adaquiz_format_grade($quiz, $quiz->grade);
            $table->align[] = 'center';
            $table->size[] = '';
        }
        if ($viewobj->canreviewmine) {
            $table->head[] = get_string('review', 'adaquiz');
            $table->align[] = 'center';
            $table->size[] = '';
        }
        if ($viewobj->feedbackcolumn) {
            $table->head[] = get_string('feedback', 'adaquiz');
            $table->align[] = 'left';
            $table->size[] = '';
        }

        if ($viewobj->attemptcolumn) {
            $attemptNumber = array();
            $count = 1;
            foreach ($viewobj->attempts as $attemptobj) {
                if ($attemptobj->preview == 0){
                    $attemptNumber[$attemptobj->id] = $count;
                    $count++;
                }
            }
        }
        
        // One row for each attempt.
        foreach ($viewobj->attempts as $attemptobj) {
            
            $attemptoptions = $attemptobj->get_display_options(true);
            $row = array();

            // Add the attempt number.
            if ($viewobj->attemptcolumn) {
                if ($attemptobj->preview == 0){
                    $row[] = $attemptNumber[$attemptobj->id];
                }else{
                    $row[] = get_string('preview', 'adaquiz');
                }
            }

            //Attempt state
            $row[] = $this->attempt_state($attemptobj);

            if ($viewobj->markcolumn) {
                $maximumMark = adaquiz_get_max_mark($attemptobj);
                if ($attemptoptions->marks >= question_display_options::MARK_AND_MAX &&
                        $attemptobj->is_finished()) {
                    $row[] = adaquiz_format_grade($quiz, $attemptobj->get_sum_marks()) . ' / ' . adaquiz_format_grade($quiz, $maximumMark);
                } else {
                    $row[] = '';
                }
            }

            // Ouside the if because we may be showing feedback but not grades.
            $attemptgrade = adaquiz_rescale_grade_more_attempts($attemptobj);
            
            if ($viewobj->gradecolumn) {
                if ($attemptoptions->marks >= question_display_options::MARK_AND_MAX &&
                        $attemptobj->is_finished()) {

                    // Highlight the highest grade if appropriate.
                    if ($viewobj->overallstats && !$attemptobj->is_preview()
                            && $viewobj->numattempts > 1 && !is_null($viewobj->mygrade)
                            && $attemptgrade == $viewobj->mygrade
                            && $quiz->grademethod == QUIZ_GRADEHIGHEST) {
                        $table->rowclasses[$attemptobj->get_attempt_number()] = 'bestrow';
                    }

                    $row[] = adaquiz_format_grade($quiz, $attemptgrade);
                } else {
                    $row[] = '';
                }
            }

            if ($viewobj->canreviewmine) {
                if ($attemptobj->state == 2){
                    $revURL = new moodle_url('/mod/adaquiz/review.php', array('attempt' => $attemptobj->id, 'cmid' => $cmid));
                    $row[] = html_writer::tag('a', get_string('review', 'adaquiz'), array('href' => $revURL));
                }
            }

            if ($viewobj->feedbackcolumn && $attemptobj->is_finished()) {
                if ($attemptoptions->overallfeedback) {
                    $row[] = quiz_feedback_for_grade($attemptgrade, $quiz, $context);
                } else {
                    $row[] = '';
                }
            }

            if ($attemptobj->preview == 0){
                $table->data[$attemptNumber[$attemptobj->id]] = $row;
            } else {
                $table->data['preview'] = $row;
            }
        } // End of loop over attempts.

        $output = '';
        $output .= $this->view_table_heading();
        $output .= html_writer::table($table);
        return $output;
    }    

    /**
     * Generate a brief textual desciption of the current state of an attempt.
     * @param quiz_attempt $attemptobj the attempt
     * @param int $timenow the time to use as 'now'.
     * @return string the appropriate lang string to describe the state.
     */
    public function attempt_state($attemptobj) {
        switch ($attemptobj->state) {
            case Attempt::STATE_ANSWERING:
                return get_string('stateinprogress', 'adaquiz');
                
            case Attempt::STATE_FINISHED:
                return get_string('statefinished', 'adaquiz') . html_writer::tag('span',
                        get_string('statefinisheddetails', 'adaquiz', userdate($attemptobj->timemodified)), array('class' => 'statedetails'));
                
            /*case Attempt::STATE_REVIEWING:
                return 'Reviewing';*/
        }
    }    
    
    
    /**
     * Generates the table heading.
     */
    public function view_table_heading() {
        return $this->heading(get_string('summaryofattempts', 'adaquiz'));
    }
    
    
    public function view_result_info($quiz, $context, $cm, $viewobj) {
        $output = '';
        if (!$viewobj->numattempts && !$viewobj->gradecolumn && is_null($viewobj->mygrade)) {
            return $output;
        }
        $resultinfo = '';

        if ($viewobj->overallstats) {
            if ($viewobj->moreattempts) {
                $a = new stdClass();
                $a->method = adaquiz_get_grading_option_name($quiz->grademethod);
                $a->mygrade = adaquiz_format_grade($quiz, $viewobj->mygrade);
                $a->quizgrade = adaquiz_format_grade($quiz, $quiz->grade);
                $resultinfo .= $this->heading(get_string('gradesofar', 'adaquiz', $a), 2, 'main');
            } else {
                $a = new stdClass();
                $a->grade = adaquiz_format_grade($quiz, $viewobj->mygrade);
                $a->maxgrade = adaquiz_format_grade($quiz, $quiz->grade);
                $a = get_string('outofshort', 'adaquiz', $a);
                $resultinfo .= $this->heading(get_string('yourfinalgradeis', 'adaquiz', $a), 2,
                        'main');
            }
        }
        if ($viewobj->mygradeoverridden) {
            $resultinfo .= html_writer::tag('p', get_string('overriddennotice', 'grades'),
                    array('class' => 'overriddennotice'))."\n";
        }
        if ($viewobj->gradebookfeedback) {
            $resultinfo .= $this->heading(get_string('comment', 'adaquiz'), 3, 'main');
            $resultinfo .= '<p class="quizteacherfeedback">'.$viewobj->gradebookfeedback.
                    "</p>\n";
        }
        if ($viewobj->feedbackcolumn) {
            $resultinfo .= $this->heading(get_string('overallfeedback', 'adaquiz'), 3, 'main');
            $resultinfo .= html_writer::tag('p',
                    adaquiz_feedback_for_grade($viewobj->mygrade, $quiz, $context),
                    array('class' => 'quizgradefeedback'))."\n";
        }
        if ($resultinfo) {
            $output .= $this->box($resultinfo, 'generalbox', 'feedback');
        }
        return $output;
    }    
    
    /**
     * Attempt Page
     *
     * @param quiz_attempt $attemptobj Instance of quiz_attempt
     * @param int $page Current page number
     * @param quiz_access_manager $accessmanager Instance of quiz_access_manager
     * @param array $messages An array of messages
     * @param array $slots Contains an array of integers that relate to questions
     * @param int $id The ID of an attempt
     * @param int $nextpage The number of the next page
     */
    public function attempt_page($attempt, $page, $slots, $id,
            $nextpage, $qnum = -1, $nextnode = null) {
        $output = '';
        $output .= $this->header();
        $output .= $this->attempt_form($attempt, $page, $slots, $id, $nextpage, $qnum, $nextnode);
        $output .= $this->footer();
        return $output;
    }    

    /**
     * Ouputs the form for making an attempt
     *
     * @param quiz_attempt $attemptobj
     * @param int $page Current page number
     * @param array $slots Array of integers relating to questions
     * @param int $id ID of the attempt
     * @param int $nextpage Next page number
     */
    public function attempt_form($attempt, $page, $slots, $id, $nextpage, $qnum = -1, $nextnode = null) {
        global $slot;
        
        $attemptobj = $attempt->attemptobj;
        if (isset($attempt->actualnode)){
            $actualnode = $attempt->actualnode;    
        }
        $adaquizobj = $attempt->adaquizobj;
        unset($attempt);
        $output = '';

        $nslots = $attemptobj->get_num_pages();
        
        // Start the form.
        $output .= html_writer::start_tag('form',
                array('action' => $attemptobj->processattempt_url(), 'method' => 'post',
                'enctype' => 'multipart/form-data', 'accept-charset' => 'utf-8',
                'id' => 'responseform'));
        $output .= html_writer::start_tag('div');

        // Print all the questions.
        foreach ($slots as $slot) {
            $output .= $attemptobj->render_question($slot, false, $attemptobj->attempt_url($slot, $page), $qnum);
        }

        $output .= html_writer::start_tag('div', array('class' => 'submitbtns'));
        if (!is_null($nextnode)){
            if ($actualnode->options[Node::OPTION_LETSTUDENTJUMP] == 1){
                $jumps = $actualnode->getJump();
                foreach($jumps->singlejumps as $key => $j){
                    $nextnode = -1;
                    $output .= html_writer::empty_tag('input', array('type' => 'submit', 'name' => 'fn'.$j->id,
                            'value' => $j->name));                    
                    foreach($adaquizobj->nodes as $key => $n){
                        if ($n->id == $j->nodeto){
                            $nextnode = $n->position;
                        }
                    }
                    $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'nextpage_fn'.$j->id,
                            'value' => $nextnode));
                }
            }else{
                if ($nextnode == -1){
                    $output .= html_writer::empty_tag('input', array('type' => 'submit', 'name' => 'summary',
                            'value' => get_string('next')));
                }else{
                    $output .= html_writer::empty_tag('input', array('type' => 'submit', 'name' => 'next',
                            'value' => get_string('next')));
                }
                $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'nextpage',
                'value' => $nextnode));                
            }
            $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'nav',
                    'value' => 0));            
            $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'remid',
                    'value' => 1));
        }
        
        if ($nextpage){
            if ($slot<$nslots){
                $output .= html_writer::start_tag('a', array('href' => $attemptobj->attempt_url($slot+1, $page, null, null, 1)));
                $output .= 'Next';
                $output .= html_writer::end_tag('a');
            }else if ($slot == $nslots){
                $output .= html_writer::start_tag('a', array('href' => $attemptobj->summary_url($attemptobj->get_cmid())));
                $output .= 'Finish';
                $output .= html_writer::end_tag('a');
            }
        }
        
        $output .= html_writer::end_tag('div');

        // Some hidden fields to trach what is going on.
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'attempt',
                'value' => $attemptobj->get_attemptid()));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'thispage',
                'value' => $page, 'id' => 'followingpage'));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'timeup',
                'value' => '0', 'id' => 'timeup'));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey',
                'value' => sesskey()));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'scrollpos',
                'value' => '', 'id' => 'scrollpos'));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'cmid',
                'value' => $attemptobj->get_cm()->id));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'slot',
                'value' => implode(',', $slots)));        
        if ($qnum != -1){
            $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'qnum',
                    'value' => $qnum+1));        
        }
        // Add a hidden field with questionids. Do this at the end of the form, so
        // if you navigate before the form has finished loading, it does not wipe all
        // the student's answers.
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'slots',
                'value' => implode(',', $slots)));
        
        // Finish the form.
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('form');

        return $output;
    }    

    /**
     * Outputs the navigation block panel
     *
     * @param quiz_nav_panel_base $panel instance of quiz_nav_panel_base
     */
    public function navigation_panel(quiz_nav_panel_base $panel, $route = -1) {
        $output = '';
        if ($route != -1) {
	        foreach($route as $key => $value){
	            if ($value != -1){
	                $route[$key] = $value -1;
	            }
	        }
        }
        /*$userpicture = $panel->user_picture();
        if ($userpicture) {
            $output .= html_writer::tag('div', $this->render($userpicture),
                    array('id' => 'user-picture', 'class' => 'clearfix'));
        }*/
        $output .= $panel->render_before_button_bits($this);

        $output .= html_writer::start_tag('div', array('class' => 'qn_buttons'));

        $out_array = array();
        $qbutton = $panel->get_question_buttons();
        $qnumber = 1;
        foreach ($qbutton as $key => $value) {
            if ($route != -1){
                $pos = array_search($key, $route);
                if ($pos !== false){
                    $value->number = $pos+1;
                    $out_array[$pos] = $this->render($value);
                }                
            }else{
                $value->number = $qnumber;
                $output .= $this->render($value);
                $qnumber++;
            }
        }

        if ($route != -1){
            for ($i = 0; $i < count($out_array); $i++){
                $output .= $out_array[$i];
            }
        }
        
        $output .= html_writer::end_tag('div');

        $output .= html_writer::tag('div', $panel->render_end_bits($this),
                array('class' => 'othernav'));

        //FIXME This was M.adamod_quiz.nav.init instead of be M.mod_adaquiz.nav.init and it hasn't worked for a LONG time
        //I'm not sure what this js was meant to do when it worked but using it now ruins everything else so I leave it commented
        //$this->page->requires->js_init_call('M.mod_adaquiz.nav.init ', null, false, adaquiz_get_js_module());

        return $output;
    } 


    
    /**
     * Returns the quizzes navigation button
     *
     * @param quiz_nav_question_button $button
     */
    protected function render_quiz_nav_question_button(quiz_nav_question_button $button) {
        //$classes = array('qnbutton', $button->stateclass, $button->navmethod);
        $classes = array('qnbutton', $button->stateclass);
        $attributes = array();

        if ($button->currentpage) {
            $classes[] = 'thispage';
            $attributes[] = get_string('onthispage', 'adaquiz');
        }

        // Flagged?
        if ($button->flagged) {
            $classes[] = 'flagged';
            $flaglabel = get_string('flagged', 'question');
        } else {
            $flaglabel = '';
        }
        $attributes[] = html_writer::tag('span', $flaglabel, array('class' => 'flagstate'));

        if (is_numeric($button->number)) {
            $qnostring = 'questionnonav';
        } else {
            $qnostring = 'questionnonavinfo';
        }

        $a = new stdClass();
        $a->number = $button->number;
        $a->attributes = implode(' ', $attributes);
        $tagcontents = html_writer::tag('span', '', array('class' => 'thispageholder')) .
                        html_writer::tag('span', '', array('class' => 'trafficlight')) .
                        get_string($qnostring, 'adaquiz', $a);
        $tagattributes = array('class' => implode(' ', $classes), 'id' => $button->id,
                                  'title' => $button->statestring);

        if ($button->url) {
            return html_writer::link($button->url, $tagcontents, $tagattributes);
        } else {
            return html_writer::tag('span', $tagcontents, $tagattributes);
        }
    }    

    /**
     * Return the HTML of the quiz timer.
     * @return string HTML content.
     */
    public function countdown_timer(adaquiz_attempt $attemptobj, $timenow) {

        $timeleft = $attemptobj->get_time_left($timenow);
        if ($timeleft !== false) {
            // Make sure the timer starts just above zero. If $timeleft was <= 0, then
            // this will just have the effect of causing the quiz to be submitted immediately.
            $timerstartvalue = max($timeleft, 1);
            $this->initialise_timer($timerstartvalue);
        }

        return html_writer::tag('div', get_string('timeleft', 'adaquiz') . ' ' .
                html_writer::tag('span', '', array('id' => 'quiz-time-left')),
                array('id' => 'quiz-timer', 'role' => 'timer',
                    'aria-atomic' => 'true', 'aria-relevant' => 'text'));
    }    
    
    /*
     * Summary Page
     */
    /**
     * Create the summary page
     *
     * @param quiz_attempt $attemptobj
     * @param mod_quiz_display_options $displayoptions
     */
    public function summary_page($attemptobj, $displayoptions, $route = -1) {
        $output = '';
        $output .= $this->header();
        $output .= $this->heading(format_string($attemptobj->get_quiz_name()));
        $output .= $this->heading(get_string('summaryofattempt', 'adaquiz'), 3);
        $output .= $this->summary_table($attemptobj, $displayoptions, $route);
        $output .= $this->summary_page_controls($attemptobj);
        $output .= $this->footer();
        return $output;
    }    

    /**
     * Generates the table of summarydata
     *
     * @param quiz_attempt $attemptobj
     * @param mod_quiz_display_options $displayoptions
     */
    public function summary_table($attemptobj, $displayoptions, $route = -1) {
        // Prepare the summary table header.
        $table = new html_table();
        $table->attributes['class'] = 'generaltable quizsummaryofattempt boxaligncenter';
        $table->head = array(get_string('question', 'adaquiz'), get_string('status', 'adaquiz'));
        $table->align = array('left', 'left');
        $table->size = array('', '');
        $markscolumn = $displayoptions->marks >= question_display_options::MARK_AND_MAX;
        if ($markscolumn) {
            $table->head[] = get_string('marks', 'adaquiz');
            $table->align[] = 'left';
            $table->size[] = '';
        }
        $table->data = array();

        // Get the summary info for each question.
        $questionNumber = 1;
        foreach ($route as $slot) {
            if ($slot != -1){
                if (!$attemptobj->is_real_question($slot)) {
                    continue;
                }
                $flag = '';
                if ($attemptobj->is_question_flagged($slot)) {
                    $flag = html_writer::empty_tag('img', array('src' => $this->pix_url('i/flagged'),
                            'alt' => get_string('flagged', 'question'), 'class' => 'questionflag'));
                }
                if ($attemptobj->can_navigate_to($slot)) {
                    $row = array(html_writer::link($attemptobj->attempt_url($slot, $slot-1, null, null, 1), 
                            $questionNumber . $flag),
                            $attemptobj->get_question_status($slot, $displayoptions->correctness));
                } else {
                    $row = array($attemptobj->get_question_number($slot) . $flag,
                                    $attemptobj->get_question_status($slot, $displayoptions->correctness));
                }
                if ($markscolumn) {
                    $row[] = $attemptobj->get_question_mark($slot);
                }
                $table->data[] = $row;
                $table->rowclasses[] = $attemptobj->get_question_state_class(
                        $slot, $displayoptions->correctness);
                $questionNumber++;
            }
        }

        // Print the summary table.
        $output = html_writer::table($table);

        return $output;
    }

    private function getAttemptedSlots($attemptid){
        global $DB;
        
        $nodeIds = $DB->get_records(NodeAttempt::TABLE, array('attempt' => $attemptid), '', 'node');
        $nodes = array_keys($nodeIds);
        $nodes = implode($nodes, ','); 
        $nodes = '(' . $nodes . ')';
        $select = 'id in ' . $nodes;
        
        $slots = $DB->get_records_select(Node::TABLE, $select, null, '', 'position');
        $slots = array_keys($slots);
        
        foreach($slots as $key => $value){
            $slots[$key] = $value += 1;
        }
        
        return $slots;
    }
    
    
    /**
     * Creates any controls a the page should have.
     *
     * @param quiz_attempt $attemptobj
     */
    public function summary_page_controls($attemptobj) {
        $output = '';

        // Return to place button.
        if ($attemptobj->get_state() == adaquiz_attempt::IN_PROGRESS) {
            $button = new single_button(
                    new moodle_url($attemptobj->attempt_url(null, $attemptobj->get_currentpage())),
                    get_string('returnattempt', 'adaquiz'));
            $output .= $this->container($this->container($this->render($button),
                    'controls'), 'submitbtns mdl-align');
        }

        // Finish attempt button.
        $options = array(
            'attempt' => $attemptobj->get_attemptid(),
            'finishattempt' => 1,
            'timeup' => 0,
            'slots' => '',
            'sesskey' => sesskey(),
            'cmid' => $attemptobj->get_cmid(),
        );

        $button = new single_button(
                new moodle_url($attemptobj->processattempt_url(), $options),
                get_string('submitallandfinish', 'adaquiz'));
        $button->id = 'responseform';
        if ($attemptobj->get_state() == adaquiz_attempt::IN_PROGRESS) {
            $button->add_action(new confirm_action(get_string('confirmclose', 'adaquiz'), null,
                    get_string('submitallandfinish', 'adaquiz')));
        }

        $duedate = $attemptobj->get_due_date();
        $message = '';
        if ($attemptobj->get_state() == adaquiz_attempt::OVERDUE) {
            $message = get_string('overduemustbesubmittedby', 'adaquiz', userdate($duedate));

        } else if ($duedate) {
            $message = get_string('mustbesubmittedby', 'adaquiz', userdate($duedate));
        }

        $output .= $this->countdown_timer($attemptobj, time());
        $output .= $this->container($message . $this->container(
                $this->render($button), 'controls'), 'submitbtns mdl-align');

        return $output;
    }    

    /**
     * Returns either a liink or button
     *
     * @param $url contains a url for the review link
     */
    public function finish_review_link($url) {

        // This is an ugly hack to fix MDL-34733 without changing the renderer API.
        global $attemptobj;
        if (!empty($attemptobj)) {
            // I think that every page in standard Moodle that ends up calling
            // this method will actually end up coming down this branch.
            $inpopup = $attemptobj->get_access_manager(time())->attempt_must_be_in_popup();
        } else {
            // Else fall back to old (not very good) heuristic.
            $inpopup = $this->page->pagelayout == 'popup';
        }

        if ($inpopup) {
            // In a 'secure' popup window.
            $this->page->requires->js_init_call('M.mod_adaquiz.secure_window.init_close_button',
                    array($url), adaquiz_get_js_module());
            return html_writer::empty_tag('input', array('type' => 'button',
                    'value' => get_string('finishreview', 'adaquiz'),
                    'id' => 'secureclosebutton'));
        } else {
            return html_writer::link($url, get_string('finishreview', 'adaquiz'));
        }
    }    

    /**
     * Create a preview link
     *
     * @param $url contains a url to the given page
     */
    public function restart_preview_button($url) {
        return $this->single_button($url, get_string('startnewpreview', 'adaquiz'));
    }    
    
    /**
     * Builds the review page
     *
     * @param quiz_attempt $attemptobj an instance of quiz_attempt.
     * @param array $slots an array of intgers relating to questions.
     * @param int $page the current page number
     * @param bool $showall whether to show entire attempt on one page.
     * @param bool $lastpage if true the current page is the last page.
     * @param mod_quiz_display_options $displayoptions instance of mod_quiz_display_options.
     * @param array $summarydata contains all table data
     * @return $output containing html data.
     */
    public function review_page(adaquiz_attempt $attemptobj, $slots, $page, $showall,
                                $lastpage, mod_quiz_display_options $displayoptions,
                                $summarydata, $route = null) {

        $output = '';
        $output .= $this->header();
        $output .= $this->review_summary_table($summarydata, $page);
        $output .= $this->review_form($page, $showall, $displayoptions,
                $this->questions($attemptobj, true, $slots, $page, $showall, $displayoptions, $route),
                $attemptobj);

        $output .= $this->review_next_navigation($attemptobj, $page, $lastpage, $route);
        $output .= $this->footer();
        return $output;
    }
    
    /**
     * Outputs the table containing data from summary data array
     *
     * @param array $summarydata contains row data for table
     * @param int $page contains the current page number
     */
    public function review_summary_table($summarydata, $page) {
        $summarydata = $this->filter_review_summary_table($summarydata, $page);
        if (empty($summarydata)) {
            return '';
        }

        $output = '';
        $output .= html_writer::start_tag('table', array(
                'class' => 'generaltable generalbox quizreviewsummary'));
        $output .= html_writer::start_tag('tbody');
        foreach ($summarydata as $rowdata) {
            if ($rowdata['title'] instanceof renderable) {
                $title = $this->render($rowdata['title']);
            } else {
                $title = $rowdata['title'];
            }

            if ($rowdata['content'] instanceof renderable) {
                $content = $this->render($rowdata['content']);
            } else {
                $content = $rowdata['content'];
            }

            $output .= html_writer::tag('tr',
                html_writer::tag('th', $title, array('class' => 'cell', 'scope' => 'row')) .
                        html_writer::tag('td', $content, array('class' => 'cell'))
            );
        }

        $output .= html_writer::end_tag('tbody');
        $output .= html_writer::end_tag('table');
        return $output;
    }    
    
    /**
     * Filters the summarydata array.
     *
     * @param array $summarydata contains row data for table
     * @param int $page the current page number
     * @return $summarydata containing filtered row data
     */
    protected function filter_review_summary_table($summarydata, $page) {
        if ($page == 0) {
            return $summarydata;
        }

        // Only show some of summary table on subsequent pages.
        foreach ($summarydata as $key => $rowdata) {
            if (!in_array($key, array('user', 'attemptlist'))) {
                unset($summarydata[$key]);
            }
        }

        return $summarydata;
    }    
    
    /**
     * outputs the link the other attempts.
     *
     * @param mod_quiz_links_to_other_attempts $links
     */
    protected function render_mod_adaquiz_links_to_other_attempts(
            mod_adaquiz_links_to_other_attempts $links) {
        $attemptlinks = array();
        foreach ($links->links as $attempt => $url) {
            if ($url) {
                $attemptlinks[] = html_writer::link($url, $attempt);
            } else {
                $attemptlinks[] = html_writer::tag('strong', $attempt);
            }
        }
        return implode(', ', $attemptlinks);
    }    

    /**
     * Renders each question
     *
     * @param quiz_attempt $attemptobj instance of quiz_attempt
     * @param bool $reviewing
     * @param array $slots array of intgers relating to questions
     * @param int $page current page number
     * @param bool $showall if true shows attempt on single page
     * @param mod_quiz_display_options $displayoptions instance of mod_quiz_display_options
     */
    public function questions(adaquiz_attempt $attemptobj, $reviewing, $slots, $page, $showall,
                              mod_quiz_display_options $displayoptions, $route = null) {
        global $slot;
        
        $out_array = array();
        $output = '';
        foreach($route as $key => $value){
            if ($value != -1){
                $route[$key] = $value - 1;
                
            }
        }

        foreach ($slots as $key => $slot) {
            $pos = array_search($key, $route);
            $qnum = array_search($slot-1, $route)+1;
            if($pos !== false){
                $out_array[$pos] = $attemptobj->render_question($slot, $reviewing,
                        $attemptobj->review_url($slot, $page, $showall), $qnum);
            }
        }
        
        for ($i = 0; $i < count($out_array); $i++){
            $output .= $out_array[$i];
        }
        
        return $output;
    }    
    
    /**
     * Renders the main bit of the review page.
     *
     * @param array $summarydata contain row data for table
     * @param int $page current page number
     * @param mod_quiz_display_options $displayoptions instance of mod_quiz_display_options
     * @param $content contains each question
     * @param quiz_attempt $attemptobj instance of quiz_attempt
     * @param bool $showall if true display attempt on one page
     */
    public function review_form($page, $showall, $displayoptions, $content, $attemptobj) {
        if ($displayoptions->flags != question_display_options::EDITABLE) {
            return $content;
        }

        $this->page->requires->js_init_call('M.mod_adaquiz.init_review_form', null, false,
                adaquiz_get_js_module());

        $output = '';
        $output .= html_writer::start_tag('form', array('action' => $attemptobj->review_url(null,
                $page, $showall), 'method' => 'post', 'class' => 'questionflagsaveform'));
        $output .= html_writer::start_tag('div');
        $output .= $content;
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey',
                'value' => sesskey()));
        $output .= html_writer::start_tag('div', array('class' => 'submitbtns'));
        $output .= html_writer::empty_tag('input', array('type' => 'submit',
                'class' => 'questionflagsavebutton', 'name' => 'savingflags',
                'value' => get_string('saveflags', 'question')));
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('form');

        return $output;
    }    
    
    /**
     * Creates a next page arrow or the finishing link
     *
     * @param quiz_attempt $attemptobj instance of quiz_attempt
     * @param int $page the current page
     * @param bool $lastpage if true current page is the last page
     */
    public function review_next_navigation(adaquiz_attempt $attemptobj, $page, $lastpage, $route = null) {
        if ($lastpage) {
            $nav = $this->finish_review_link($attemptobj->view_url());
        } else {
            //Look for next page
            foreach($route as $key => $value){
                if ($value != -1){
                    $route[$key] = $value-1;
                }
            }
            $pos = array_search($page, $route);
            $next = $route[$pos+1];
            $nav = link_arrow_right(get_string('next'), $attemptobj->review_url(null, $next));
        }
        return html_writer::tag('div', $nav, array('class' => 'submitbtns'));
    }    
    
}

class mod_adaquiz_links_to_other_attempts implements renderable {
    /**
     * @var array string attempt number => url, or null for the current attempt.
     */
    public $links = array();
}


class mod_adaquiz_view_object {
    /** @var array $infomessages of messages with information to display about the quiz. */
    public $infomessages;
    /** @var array $attempts contains all the user's attempts at this quiz. */
    public $attempts;
    /** @var array $attemptobjs quiz_attempt objects corresponding to $attempts. */
    public $attemptobjs;
    /** @var quiz_access_manager $accessmanager contains various access rules. */
    public $accessmanager;
    /** @var bool $canreviewmine whether the current user has the capability to
     *       review their own attempts. */
    public $canreviewmine;
    /** @var bool $canedit whether the current user has the capability to edit the quiz. */
    public $canedit;
    /** @var moodle_url $editurl the URL for editing this quiz. */
    public $editurl;
    /** @var int $attemptcolumn contains the number of attempts done. */
    public $attemptcolumn;
    /** @var int $gradecolumn contains the grades of any attempts. */
    public $gradecolumn;
    /** @var int $markcolumn contains the marks of any attempt. */
    public $markcolumn;
    /** @var int $overallstats contains all marks for any attempt. */
    public $overallstats;
    /** @var string $feedbackcolumn contains any feedback for and attempt. */
    public $feedbackcolumn;
    /** @var string $timenow contains a timestamp in string format. */
    public $timenow;
    /** @var int $numattempts contains the total number of attempts. */
    public $numattempts;
    /** @var float $mygrade contains the user's final grade for a quiz. */
    public $mygrade;
    /** @var bool $moreattempts whether this user is allowed more attempts. */
    public $moreattempts;
    /** @var int $mygradeoverridden contains an overriden grade. */
    public $mygradeoverridden;
    /** @var string $gradebookfeedback contains any feedback for a gradebook. */
    public $gradebookfeedback;
    /** @var bool $unfinished contains 1 if an attempt is unfinished. */
    public $unfinished;
    /** @var object $lastfinishedattempt the last attempt from the attempts array. */
    public $lastfinishedattempt;
    /** @var array $preventmessages of messages telling the user why they can't
     *       attempt the quiz now. */
    public $preventmessages;
    /** @var string $buttontext caption for the start attempt button. If this is null, show no
     *      button, or if it is '' show a back to the course button. */
    public $buttontext;
    /** @var string $startattemptwarning alert to show the user before starting an attempt. */
    public $startattemptwarning;
    /** @var moodle_url $startattempturl URL to start an attempt. */
    public $startattempturl;
    /** @var moodle_url $startattempturl URL for any Back to the course button. */
    public $backtocourseurl;
    /** @var bool whether the attempt must take place in a popup window. */
    public $popuprequired;
    /** @var array options to use for the popup window, if required. */
    public $popupoptions;
    /** @var bool $quizhasquestions whether the quiz has any questions. */
    public $quizhasquestions;
}