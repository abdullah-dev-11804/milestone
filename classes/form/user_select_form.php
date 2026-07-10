<?php
namespace local_sentaldocupload\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class user_select_form extends moodleform {
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $users = $DB->get_records_sql_menu(
            "SELECT id, " . $DB->sql_concat_join("' '", ['firstname', 'lastname']) . "
               FROM {user}
              WHERE deleted = 0
                AND suspended = 0
                AND id > 1
           ORDER BY lastname, firstname",
            null,
            0,
            500
        );

        $options = ['' => get_string('selectuser', 'local_sentaldocupload')] + $users;
        $mform->addElement('select', 'userid', get_string('selectuser', 'local_sentaldocupload'), $options);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('loadcourses', 'local_sentaldocupload'));
    }
}
