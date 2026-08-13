<?php
// Publicly serve EDS/NCA course-completion PDFs for Public Profile.

define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

$jobid = required_param('jobid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$preview = optional_param('preview', 0, PARAM_BOOL);
$inline = optional_param('inline', 0, PARAM_BOOL);

$active = local_sentaldocupload_get_active_eds_course_completion_document($courseid, $userid);
if (!$active || (int)$active->id !== $jobid) {
    throw new moodle_exception('filenotfound');
}

$context = context_system::instance();
$fs = get_file_storage();

$files = $fs->get_area_files(
    $context->id,
    'local_ncasign',
    \local_ncasign\local\job_manager::FILEAREA_PUBLICPROFILEPDF,
    $jobid,
    'id DESC',
    false
);
$file = reset($files);

if (!$file && !local_sentaldocupload_ncasign_job_has_public_hidden_customcert_pages($jobid)) {
    $files = $fs->get_area_files(
        $context->id,
        'local_ncasign',
        \local_ncasign\local\job_manager::FILEAREA_SIGNEDPDF,
        $jobid,
        'id DESC',
        false
    );
    $file = reset($files);
}

if (!$file) {
    throw new moodle_exception('filenotfound');
}

send_stored_file($file, 0, 0, !($preview || $inline));
