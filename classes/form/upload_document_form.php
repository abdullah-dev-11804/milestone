<?php
namespace local_sentaldocupload\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class upload_document_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $userid = (int)($customdata['userid'] ?? 0);
        $courses = $customdata['courses'] ?? [];
        $validitymap = $customdata['validitymap'] ?? [];

        $mform->addElement('hidden', 'userid', $userid);
        $mform->setType('userid', PARAM_INT);

        $courseoptions = [];
        foreach ($courses as $course) {
            $days = (int)($validitymap[$course->id] ?? 0);
            $courseoptions[$course->id] = format_string($course->fullname) . ' (' . get_string('validitydays', 'local_sentaldocupload') . ': ' . $days . ')';
        }

        $mform->addElement('select', 'courseid', get_string('selectcourse', 'local_sentaldocupload'), $courseoptions);
        $mform->setType('courseid', PARAM_INT);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $types = [
            'type1' => get_string('doctype_type1', 'local_sentaldocupload'),
            'type2' => get_string('doctype_type2', 'local_sentaldocupload'),
        ];
        $mform->addElement('select', 'documenttype', get_string('documenttype', 'local_sentaldocupload'), $types);
        $mform->setType('documenttype', PARAM_ALPHAEXT);
        $mform->addRule('documenttype', null, 'required', null, 'client');

        $mform->addElement('date_selector', 'issuedate', get_string('issuedate', 'local_sentaldocupload'));
        $mform->addRule('issuedate', null, 'required', null, 'client');

        $mform->addElement('html', '<div id="id_expirypreview" class="alert alert-info" role="status"></div>');

        $fileoptions = [
            'subdirs' => 0,
            'maxbytes' => 10 * 1024 * 1024,
            'maxfiles' => 1,
            'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png'],
        ];
        $mform->addElement('filepicker', 'documentfile', get_string('documentfile', 'local_sentaldocupload'), null, $fileoptions);
        $mform->addHelpButton('documentfile', 'allowedfiles', 'local_sentaldocupload');
        $mform->addRule('documentfile', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('uploaddocument', 'local_sentaldocupload'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['issuedate'])) {
            $errors['issuedate'] = get_string('missingissuedate', 'local_sentaldocupload');
        }
        return $errors;
    }
}
