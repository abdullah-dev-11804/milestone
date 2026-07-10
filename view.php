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

$sql = "SELECT v.*, v.id AS versionid,
               d.courseid,
               d.documenttype,
               d.issuedate AS docissuedate,
               d.expirydate AS docexpirydate
          FROM {sental_modeb_doc_version} v
          JOIN {sental_modeb_doc} d ON d.id = v.documentid
         WHERE v.id = :versionid";
$first = $DB->get_record_sql($sql, ['versionid' => $versionid], MUST_EXIST);
$audituserid = (int)$DB->get_field('sental_modeb_doc_user', 'userid', ['documentid' => (int)$first->documentid], IGNORE_MULTIPLE);

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_sentaldocupload', 'document', $versionid, 'filename', false);
$file = reset($files);
if (!$file) {
    throw new moodle_exception('filenotfound');
}

local_sentaldocupload_audit((int)$first->documentid, $versionid, $audituserid, 'view');
local_sentaldocupload_send_file($file, false);
