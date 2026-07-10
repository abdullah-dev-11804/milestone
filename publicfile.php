<?php
// Publicly serve Type 1 manual scan files when Public Profile priority logic allows it.

// This file is intended to be linked from the SENTAL learner Public Profile page.
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

$versionid = required_param('versionid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$preview = optional_param('preview', 0, PARAM_BOOL);
$inline = optional_param('inline', 0, PARAM_BOOL);

$ducolumns = $DB->get_columns('sental_modeb_doc_user');
$versioncolumns = $DB->get_columns('sental_modeb_doc_version');

$useroverrideexpr = isset($ducolumns['publicprofileoverride'])
    ? 'COALESCE(du.publicprofileoverride, 0)'
    : '0';

$versionoverrideexpr = isset($versioncolumns['publicprofileoverride'])
    ? 'COALESCE(v.publicprofileoverride, 0)'
    : '0';

$sql = "SELECT v.*,
               COALESCE(v.showinpublicprofile, 0) AS version_showinpublicprofile,
               $versionoverrideexpr AS version_publicprofileoverride,
               d.courseid,
               d.documenttype,
               d.currentversion,
               COALESCE(d.showinpublicprofile, 0) AS doc_showinpublicprofile,
               du.userid,
               COALESCE(du.showinpublicprofile, 0) AS user_showinpublicprofile,
               $useroverrideexpr AS user_publicprofileoverride
          FROM {sental_modeb_doc_version} v
          JOIN {sental_modeb_doc} d ON d.id = v.documentid
          JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
         WHERE v.id = :versionid
           AND du.userid = :userid
           AND d.courseid = :courseid
           AND d.documenttype = :doctype";
$record = $DB->get_record_sql($sql, [
    'versionid' => $versionid,
    'userid' => $userid,
    'courseid' => $courseid,
    'doctype' => 'type1',
]);

if (!$record) {
    throw new moodle_exception('filenotfound');
}

// Same rule as public profile card rendering:
// Type 1 only, and at least one public-profile flag must be checked.
// Older versions may have saved the checkbox on the document/user level only.
$ispublic = !empty($record->version_showinpublicprofile)
    || !empty($record->version_publicprofileoverride)
    || !empty($record->user_showinpublicprofile)
    || !empty($record->user_publicprofileoverride)
    || !empty($record->doc_showinpublicprofile);

if (!$ispublic) {
    throw new moodle_exception('filenotfound');
}

$context = context_system::instance();
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_sentaldocupload', 'document', $versionid, 'filename', false);
$file = reset($files);
if (!$file) {
    throw new moodle_exception('filenotfound');
}

local_sentaldocupload_send_file($file, !($preview || $inline));
