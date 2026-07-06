<?php
// Download uploaded document version.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();

$versionid = required_param('versionid', PARAM_INT);

$sql = "SELECT v.*, v.showinpublicprofile AS version_showinpublicprofile, d.courseid, d.documenttype, d.issuedate, d.expirydate, du.userid, du.showinpublicprofile AS user_showinpublicprofile
          FROM {sental_modeb_doc_version} v
          JOIN {sental_modeb_doc} d ON d.id = v.documentid
          JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
         WHERE v.id = :versionid";
$records = $DB->get_records_sql($sql, ['versionid' => $versionid]);
if (!$records) {
    throw new moodle_exception('filenotfound');
}

$first = reset($records);
$canmanage = has_capability('local/sentaldocupload:manage', $context);
$ownsdocument = false;
foreach ($records as $record) {
    if ((int)$record->userid === (int)$USER->id) {
        $ownsdocument = true;
        $first = $record;
        break;
    }
}

if (!$canmanage && !$ownsdocument) {
    throw new required_capability_exception($context, 'local/sentaldocupload:manage', 'nopermissions', 'error');
}

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

local_sentaldocupload_audit((int)$first->documentid, $versionid, (int)$first->userid, 'download');
local_sentaldocupload_send_file($file, true);
