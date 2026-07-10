<?php
// Course-level Mode A/Mode B page is deprecated by the Milestone 2 confirmation.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/sentaldocupload:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/sentaldocupload/coursemode.php'));
$PAGE->set_title(get_string('coursemode_removed', 'local_sentaldocupload'));
$PAGE->set_heading(get_string('coursemode_removed', 'local_sentaldocupload'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursemode_removed', 'local_sentaldocupload'));
echo $OUTPUT->notification(get_string('coursemode_removed_desc', 'local_sentaldocupload'), core\output\notification::NOTIFY_INFO);
echo html_writer::link(new moodle_url('/local/sentaldocupload/index.php'), get_string('manualdocumentupload', 'local_sentaldocupload'), ['class' => 'btn btn-primary']);
echo $OUTPUT->footer();
