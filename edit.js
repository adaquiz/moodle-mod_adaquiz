// Initialise everything on the quiz edit/order and paging page.
var adaquiz_edit = {};
function adaquiz_edit_init(Y) {
    M.core_scroll_manager.scroll_to_saved_pos(Y);
    Y.on('submit', function(e) {
            M.core_scroll_manager.save_scroll_pos(Y, 'id_existingcategory');
        }, '#mform1');
    Y.on('submit', function(e) {
            M.core_scroll_manager.save_scroll_pos(Y, e.target.get('firstChild'));
        }, '.quizsavegradesform');

    // Add random question dialogue --------------------------------------------
    var randomquestiondialog = Y.YUI2.util.Dom.get('randomquestiondialog');
    if (randomquestiondialog) {
        Y.YUI2.util.Dom.get(document.body).appendChild(randomquestiondialog);
    }
 
    adaquiz_edit.randomquestiondialog = new Y.YUI2.widget.Dialog('randomquestiondialog', {
            modal: true,
            width: '100%',
            iframe: true,
            zIndex: 1000, // zIndex must be way above 99 to be above the active quiz tab
            fixedcenter: true,
            visible: false,
            close: true,
            constraintoviewport: true,
            postmethod: 'form'
    });
    adaquiz_edit.randomquestiondialog.render();
    var div = document.getElementById('randomquestiondialog');
    if (div) {
        div.style.display = 'block';
    }

    // Show the form on button click.
    Y.YUI2.util.Event.addListener(adaquiz_edit_config.dialoglisteners, 'click', function(e) {
        // Transfer the page number from the button form to the pop-up form.
        var addrandombutton = Y.YUI2.util.Event.getTarget(e);
        var addpagehidden = Y.YUI2.util.Dom.getElementsByClassName('addonpage_formelement', 'input', addrandombutton.form);
        document.getElementById('rform_qpage').value = addpagehidden[0].value;

        // Show the dialogue and stop the default action.
        adaquiz_edit.randomquestiondialog.show();
        Y.YUI2.util.Event.stopEvent(e);
    });

    // Make escape close the dialogue.
    adaquiz_edit.randomquestiondialog.cfg.setProperty('keylisteners', [new Y.YUI2.util.KeyListener(
            document, {keys:[27]}, function(types, args, obj) { adaquiz_edit.randomquestiondialog.hide();
    })]);

    // Make the form cancel button close the dialogue.
    Y.YUI2.util.Event.addListener('id_cancel', 'click', function(e) {
        adaquiz_edit.randomquestiondialog.hide();
        Y.YUI2.util.Event.preventDefault(e);
    });

    Y.YUI2.util.Event.addListener('id_existingcategory', 'click', adaquiz_yui_workaround);

    Y.YUI2.util.Event.addListener('id_newcategory', 'click', adaquiz_yui_workaround);

    // Repaginate dialogue -----------------------------------------------------
    adaquiz_edit.repaginatedialog = new Y.YUI2.widget.Dialog('repaginatedialog', {
            modal: true,
            width: '30em',
            iframe: true,
            zIndex: 1000,
            context: ['repaginatecommand', 'tr', 'br', ['beforeShow']],
            visible: false,
            close: true,
            constraintoviewport: true,
            postmethod: 'form'
    });
    adaquiz_edit.repaginatedialog.render();
    adaquiz_edit.randomquestiondialog.render();
    var div = document.getElementById('repaginatedialog');
    if (div) {
        div.style.display = 'block';
    }

    // Show the form on button click.
    Y.YUI2.util.Event.addListener('repaginatecommand', 'click', function() {
        adaquiz_edit.repaginatedialog.show();
    });

    // Reposition the dialogue when the window resizes. For some reason this was not working automatically.
    Y.YUI2.widget.Overlay.windowResizeEvent.subscribe(function() {
      adaquiz_edit.repaginatedialog.cfg.setProperty('context', ['repaginatecommand', 'tr', 'br', ['beforeShow']]);
    });

    // Make escape close the dialogue.
    adaquiz_edit.repaginatedialog.cfg.setProperty('keylisteners', [new Y.YUI2.util.KeyListener(
            document, {keys:[27]}, function(types, args, obj) { adaquiz_edit.repaginatedialog.hide();
    })]);

    // Nasty hack, remove once the YUI bug causing MDL-17594 is fixed.
    // https://sourceforge.net/tracker/index.php?func=detail&aid=2493426&group_id=165715&atid=836476
    var elementcauseinglayoutproblem = document.getElementById('_yuiResizeMonitor');
    if (elementcauseinglayoutproblem) {
        elementcauseinglayoutproblem.style.left = '0px';
    }
}

function adaquiz_yui_workaround(e) {
    // YUI does not send the button pressed with the form submission, so copy
    // the button name to a hidden input.
    var submitbutton = Y.YUI2.util.Event.getTarget(e);
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = submitbutton.name;
    input.value = 1;
    submitbutton.form.appendChild(input);
}

// Initialise everything on the quiz settings form.
function adaquiz_settings_init() {
    var repaginatecheckbox = document.getElementById('id_repaginatenow');
    if (!repaginatecheckbox) {
        // This checkbox does not appear on the create new quiz form.
        return;
    }
    var qppselect = document.getElementById('id_questionsperpage');
    var qppinitialvalue = qppselect.value;
    Y.YUI2.util.Event.addListener([qppselect, 'id_shufflequestions'] , 'change', function() {
        setTimeout(function() { // Annoyingly, this handler runs before the formlib disabledif code, hence the timeout.
            if (!repaginatecheckbox.disabled) {
                repaginatecheckbox.checked = qppselect.value != qppinitialvalue;
            }
        }, 50);
    });
}
