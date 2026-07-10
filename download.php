<?php
// Download uploaded document version.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();

$versionid = required_param('versionid', PARAM_INT);
$preview = optional_param('preview', 0, PARAM_BOOL);
$inline = optional_param('inline', 0, PARAM_BOOL);

// Do not join {sental_modeb_doc_user} here. A group-uploaded document has
// multiple learner links, and Moodle's get_records_sql() keys by the first
// selected column. If v.id is used as the key, duplicate rows overwrite each
// other and the primary learner can incorrectly receive nopermissions.
$sql = "SELECT v.*, v.id AS versionid,
               v.showinpublicprofile AS version_showinpublicprofile,
               d.courseid,
               d.documenttype,
               d.issuedate AS docissuedate,
               d.expirydate AS docexpirydate
          FROM {sental_modeb_doc_version} v
          JOIN {sental_modeb_doc} d ON d.id = v.documentid
         WHERE v.id = :versionid";
$first = $DB->get_record_sql($sql, ['versionid' => $versionid], MUST_EXIST);

$canmanage = has_capability('local/sentaldocupload:manage', $context);
$ownsdocument = $DB->record_exists('sental_modeb_doc_user', [
    'documentid' => (int)$first->documentid,
    'userid' => (int)$USER->id,
]);

if (!$canmanage && !$ownsdocument) {
    throw new required_capability_exception($context, 'local/sentaldocupload:manage', 'nopermissions', 'error');
}

$audituserid = $ownsdocument
    ? (int)$USER->id
    : (int)$DB->get_field('sental_modeb_doc_user', 'userid', ['documentid' => (int)$first->documentid], IGNORE_MULTIPLE);

// My Certifications is a private student area. Students may download any Type 1 or Type 2
// document version linked to their own user record. Public Profile visibility is checked only
// by publicfile.php / the Moodle user profile integration, not here.
if (!$canmanage) {
    if (!in_array((string)$first->documenttype, ['type1', 'type2'], true)) {
        throw new required_capability_exception($context, 'local/sentaldocupload:manage', 'nopermissions', 'error');
    }
}

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_sentaldocupload', 'document', $versionid, 'filename', false);
$file = reset($files);
if (!$file) {
    throw new moodle_exception('filenotfound');
}

local_sentaldocupload_audit((int)$first->documentid, $versionid, $audituserid, $preview ? 'view' : 'download');
local_sentaldocupload_send_file($file, !($preview || $inline));
