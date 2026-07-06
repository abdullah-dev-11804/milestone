<?php
// View uploaded document version inline and record immutable audit entry.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
if (!has_capability('local/sentaldocupload:manage', $context)) {
    redirect(new moodle_url('/local/sentaldocupload/mydocuments.php'));
}

$versionid = required_param('versionid', PARAM_INT);

$sql = "SELECT v.*, d.courseid, d.documenttype, d.issuedate, d.expirydate, du.userid
          FROM {sental_modeb_doc_version} v
          JOIN {sental_modeb_doc} d ON d.id = v.documentid
          JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
         WHERE v.id = :versionid";
$records = $DB->get_records_sql($sql, ['versionid' => $versionid]);
if (!$records) {
    throw new moodle_exception('filenotfound');
}

$first = reset($records);

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_sentaldocupload', 'document', $versionid, 'filename', false);
$file = reset($files);
if (!$file) {
    throw new moodle_exception('filenotfound');
}

local_sentaldocupload_audit((int)$first->documentid, $versionid, (int)$first->userid, 'view');
local_sentaldocupload_send_file($file, false);
