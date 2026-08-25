<?php
// Toggle Public Profile visibility for an existing uploaded document version.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/sentaldocupload:manage', $context);
require_sesskey();

$documentid = required_param('documentid', PARAM_INT);
$versionid = required_param('versionid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$showinpublic = optional_param('showinpublic', 0, PARAM_BOOL) ? 1 : 0;
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$sql = "SELECT v.id AS versionid,
               v.documentid,
               d.documenttype
          FROM {sental_modeb_doc_version} v
          JOIN {sental_modeb_doc} d ON d.id = v.documentid
         WHERE v.id = :versionid
           AND d.id = :documentid";
$record = $DB->get_record_sql($sql, [
    'versionid' => $versionid,
    'documentid' => $documentid,
], MUST_EXIST);

if ((string)$record->documenttype !== 'type1') {
    throw new moodle_exception('publicprofileonlytype1', 'local_sentaldocupload');
}

if (!$DB->record_exists('sental_modeb_doc_user', ['documentid' => $documentid, 'userid' => $userid])) {
    throw new moodle_exception('invalidrecord', 'error');
}

$now = time();

$link = $DB->get_record('sental_modeb_doc_user', ['documentid' => $documentid, 'userid' => $userid], '*', MUST_EXIST);
$link->showinpublicprofile = $showinpublic;
$link->publicprofileoverride = $showinpublic;
$DB->update_record('sental_modeb_doc_user', $link);

local_sentaldocupload_audit($documentid, $versionid, $userid, 'public_visibility');
local_sentaldocupload_refresh_public_profile_summary($documentid, $versionid);

$target = $returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/local/sentaldocupload/history.php');
redirect($target, get_string('publicprofilevisibilityupdated', 'local_sentaldocupload'), 2);
