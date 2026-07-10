<?php
namespace local_sentaldocupload\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class course_mode_form extends moodleform {
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $courses = $DB->get_records_sql_menu(
            "SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname",
            null,
            0,
            1000
        );

        $mform->addElement('static', 'warning', '', get_string('modewarning', 'local_sentaldocupload'));
        $mform->addElement('select', 'courseid', get_string('course', 'local_sentaldocupload'), $courses);
        $mform->setType('courseid', PARAM_INT);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $modes = [
            'eds' => get_string('modea', 'local_sentaldocupload'),
            'scan' => get_string('modeb', 'local_sentaldocupload'),
        ];
        $mform->addElement('select', 'mode', get_string('coursemode', 'local_sentaldocupload'), $modes);
        $mform->setType('mode', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('setmode', 'local_sentaldocupload'));
    }
}
