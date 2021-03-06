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
 * Administration settings definitions for the adaptive quiz module.
 *
 * @package    mod_adaquiz
 * @copyright  2015 Maths for More S.L.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/adaquiz/lib.php');

// First get a list of adaptive quiz reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = core_component::get_plugin_list_with_file('adaquiz', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'adaquiz_'.$report);
    $reportsbyname[$strreportname] = $report;
}
core_collator::ksort($reportsbyname);

// First get a list of adaptive quiz reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = core_component::get_plugin_list_with_file('adaquizaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'adaquizaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
core_collator::ksort($rulesbyname);

// Create the adaptive quiz settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'adaquiz');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$adaquizsettings = new admin_settingpage('modsettingadaquiz', $pagetitle, 'moodle/site:config');

if ($ADMIN->fulltree) {
    // Introductory explanation that all the settings are defaults for the add adaptive quiz form.
    $adaquizsettings->add(new admin_setting_heading('adaquizintro', '', get_string('configintro', 'adaquiz')));

    // // Time limit.
    // $adaquizsettings->add(new admin_setting_configtext_with_advanced('adaquiz/timelimit',
    //         get_string('timelimitsec', 'adaquiz'), get_string('configtimelimitsec', 'adaquiz'),
    //         array('value' => '0', 'adv' => false), PARAM_INT));

    // // What to do with overdue attempts.
    // $adaquizsettings->add(new mod_adaquiz_admin_setting_overduehandling('adaquiz/overduehandling',
    //         get_string('overduehandling', 'adaquiz'), get_string('overduehandling_desc', 'adaquiz'),
    //         array('value' => 'autosubmit', 'adv' => false), null));

    // // Grace period time.
    // $adaquizsettings->add(new admin_setting_configtext_with_advanced('adaquiz/graceperiod',
    //         get_string('graceperiod', 'adaquiz'), get_string('graceperiod_desc', 'adaquiz'),
    //         array('value' => '86400', 'adv' => false), PARAM_INT));

    // // Minimum grace period used behind the scenes.
    // $adaquizsettings->add(new admin_setting_configtext('adaquiz/graceperiodmin',
    //         get_string('graceperiodmin', 'adaquiz'), get_string('graceperiodmin_desc', 'adaquiz'),
    //         60, PARAM_INT));


    // Number of attempts.
    $options = array(get_string('unlimited'));
    for ($i = 1; $i <= ADAQUIZ_MAX_ATTEMPT_OPTION; $i++) {
        $options[$i] = $i;
    }
    $adaquizsettings->add(new admin_setting_configselect_with_advanced('adaquiz/attempts',
            get_string('attemptsallowed', 'adaquiz'), get_string('configattemptsallowed', 'adaquiz'),
            array('value' => 0, 'adv' => false), $options));

    // Grading method.
    // $adaquizsettings->add(new mod_adaquiz_admin_setting_grademethod('adaquiz/grademethod',
    //         get_string('grademethod', 'adaquiz'), get_string('configgrademethod', 'adaquiz'),
    //         array('value' => ADAQUIZ_GRADEHIGHEST, 'adv' => false), null));

    // Maximum grade.
    $adaquizsettings->add(new admin_setting_configtext('adaquiz/maximumgrade',
            get_string('maximumgrade'), get_string('configmaximumgrade', 'adaquiz'), 10, PARAM_INT));

    // Shuffle questions.
    // $adaquizsettings->add(new admin_setting_configcheckbox_with_advanced('adaquiz/shufflequestions',
    //         get_string('shufflequestions', 'adaquiz'), get_string('configshufflequestions', 'adaquiz'),
    //         array('value' => 0, 'adv' => false)));

    // Questions per page.
    // $perpage = array();
    // $perpage[0] = get_string('never');
    // $perpage[1] = get_string('aftereachquestion', 'adaquiz');
    // for ($i = 2; $i <= ADAQUIZ_MAX_QPP_OPTION; ++$i) {
    //     $perpage[$i] = get_string('afternquestions', 'adaquiz', $i);
    // }
    // $adaquizsettings->add(new admin_setting_configselect_with_advanced('adaquiz/questionsperpage',
    //         get_string('newpageevery', 'adaquiz'), get_string('confignewpageevery', 'adaquiz'),
    //         array('value' => 1, 'adv' => false), $perpage));

    // Navigation method.
    // $adaquizsettings->add(new admin_setting_configselect_with_advanced('adaquiz/navmethod',
    //         get_string('navmethod', 'adaquiz'), get_string('confignavmethod', 'adaquiz'),
    //         array('value' => ADAQUIZ_NAVMETHOD_FREE, 'adv' => true), adaquiz_get_navigation_options()));

    // Shuffle within questions.
    $adaquizsettings->add(new admin_setting_configcheckbox_with_advanced('adaquiz/shuffleanswers',
            get_string('shufflewithin', 'adaquiz'), get_string('configshufflewithin', 'adaquiz'),
            array('value' => 1, 'adv' => false)));

    // Preferred behaviour.
    // AdaptiveQuiz only Immediate Feedback and Immediate Feedback with multiple tries.
    $behaviours = question_engine::get_behaviour_options('');
        // AdaptiveQuiz: Leave only two behaviours: immediatefeedback and interactive
    $notsupportedbehaviours = array('adaptive', 'adaptivenopenalty', 'deferredfeedback', 'deferredcbm', 'immediatecbm');
    foreach($notsupportedbehaviours as $key) {
        unset($behaviours[$key]);
    }
    $adaquizsettings->add(new admin_setting_configselect('adaquiz/preferredbehaviour',
            get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'adaquiz'),
            'deferredfeedback', $behaviours));
    // $adaquizsettings->add(new admin_setting_question_behaviour('adaquiz/preferredbehaviour',
    //         get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'adaquiz'),
    //         'deferredfeedback'));



    // Each attempt builds on last.
    // $adaquizsettings->add(new admin_setting_configcheckbox_with_advanced('adaquiz/attemptonlast',
    //         get_string('eachattemptbuildsonthelast', 'adaquiz'),
    //         get_string('configeachattemptbuildsonthelast', 'adaquiz'),
    //         array('value' => 0, 'adv' => true)));

    // Review options.
    $adaquizsettings->add(new admin_setting_heading('reviewheading',
            get_string('reviewoptionsheading', 'adaquiz'), ''));
    foreach (mod_adaquiz_admin_review_setting::fields() as $field => $name) {
        $default = mod_adaquiz_admin_review_setting::all_on();
        $forceduring = null;
        if ($field == 'attempt') {
            $forceduring = true;
        } else if ($field == 'overallfeedback') {
            $default = $default ^ mod_adaquiz_admin_review_setting::DURING;
            $forceduring = false;
        }
        $adaquizsettings->add(new mod_adaquiz_admin_review_setting('adaquiz/review' . $field,
                $name, '', $default, $forceduring));
    }

    // Show the user's picture.
    $adaquizsettings->add(new mod_adaquiz_admin_setting_user_image('adaquiz/showuserpicture',
            get_string('showuserpicture', 'adaquiz'), get_string('configshowuserpicture', 'adaquiz'),
            array('value' => 0, 'adv' => false), null));

    // Decimal places for overall grades.
    $options = array();
    for ($i = 0; $i <= ADAQUIZ_MAX_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $adaquizsettings->add(new admin_setting_configselect_with_advanced('adaquiz/decimalpoints',
            get_string('decimalplaces', 'adaquiz'), get_string('configdecimalplaces', 'adaquiz'),
            array('value' => 2, 'adv' => false), $options));

    // Decimal places for question grades.
    $options = array(-1 => get_string('sameasoverall', 'adaquiz'));
    for ($i = 0; $i <= ADAQUIZ_MAX_Q_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $adaquizsettings->add(new admin_setting_configselect_with_advanced('adaquiz/questiondecimalpoints',
            get_string('decimalplacesquestion', 'adaquiz'),
            get_string('configdecimalplacesquestion', 'adaquiz'),
            array('value' => -1, 'adv' => true), $options));

    // Show blocks during adaptive quiz attempts.
    $adaquizsettings->add(new admin_setting_configcheckbox_with_advanced('adaquiz/showblocks',
            get_string('showblocks', 'adaquiz'), get_string('configshowblocks', 'adaquiz'),
            array('value' => 0, 'adv' => true)));

    // Password.
    // $adaquizsettings->add(new admin_setting_configtext_with_advanced('adaquiz/password',
    //         get_string('requirepassword', 'adaquiz'), get_string('configrequirepassword', 'adaquiz'),
    //         array('value' => '', 'adv' => true), PARAM_TEXT));

    // // IP restrictions.
    // $adaquizsettings->add(new admin_setting_configtext_with_advanced('adaquiz/subnet',
    //         get_string('requiresubnet', 'adaquiz'), get_string('configrequiresubnet', 'adaquiz'),
    //         array('value' => '', 'adv' => true), PARAM_TEXT));

    // // Enforced delay between attempts.
    // $adaquizsettings->add(new admin_setting_configtext_with_advanced('adaquiz/delay1',
    //         get_string('delay1st2nd', 'adaquiz'), get_string('configdelay1st2nd', 'adaquiz'),
    //         array('value' => 0, 'adv' => true), PARAM_INT));
    // $adaquizsettings->add(new admin_setting_configtext_with_advanced('adaquiz/delay2',
    //         get_string('delaylater', 'adaquiz'), get_string('configdelaylater', 'adaquiz'),
    //         array('value' => 0, 'adv' => true), PARAM_INT));

    // // Browser security.
    // $adaquizsettings->add(new mod_adaquiz_admin_setting_browsersecurity('adaquiz/browsersecurity',
    //         get_string('showinsecurepopup', 'adaquiz'), get_string('configpopup', 'adaquiz'),
    //         array('value' => '-', 'adv' => true), null));

    // // Allow user to specify if setting outcomes is an advanced setting.
    // if (!empty($CFG->enableoutcomes)) {
    //     $adaquizsettings->add(new admin_setting_configcheckbox('adaquiz/outcomes_adv',
    //         get_string('outcomesadvanced', 'adaquiz'), get_string('configoutcomesadvanced', 'adaquiz'),
    //         '0'));
    // }

    // // Autosave frequency.
    // $options = array(
    //       0 => get_string('donotuseautosave', 'adaquiz'),
    //      60 => get_string('oneminute', 'adaquiz'),
    //     120 => get_string('numminutes', 'moodle', 2),
    //     300 => get_string('numminutes', 'moodle', 5),
    // );
    // $adaquizsettings->add(new admin_setting_configselect('adaquiz/autosaveperiod',
    //         get_string('autosaveperiod', 'adaquiz'), get_string('autosaveperiod_desc', 'adaquiz'), 120, $options));
}

// Now, depending on whether any reports have their own settings page, add
// the adaptive quiz setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $adaquizsettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingsadaquizcat',
            get_string('modulename', 'adaquiz'), $module->is_enabled() === false));
    $ADMIN->add('modsettingsadaquizcat', $adaquizsettings);

    // Add settings pages for the adaptive quiz report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingsadaquizcat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/adaquiz/report/$reportname/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsadaquizcat', $settings);
        }
    }

    // Add settings adaptive pages for the adaptive quiz access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingsadaquizcat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/adaquiz/accessrule/$rule/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsquizcat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
