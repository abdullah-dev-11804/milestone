<?php
// Bulk toggle Public Profile visibility for uploaded course completion documents.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/sentaldocupload:manage', $context);
require_sesskey();

$items = optional_param_array('items', [], PARAM_RAW);
$action = required_param('publicaction', PARAM_ALPHA);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$showinpublic = null;
if ($action === 'show') {
    $showinpublic = 1;
} else if ($action === 'hide') {
    $showinpublic = 0;
} else {
    throw new moodle_exception('invalidrequest', 'error');
}

$changed = 0;
$summaryupdates = [];
$transaction = $DB->start_delegated_transaction();

foreach ($items as $item) {
    if (!preg_match('/^(\d+):(\d+):(\d+)$/', (string)$item, $matches)) {
        continue;
    }

    $documentid = (int)$matches[1];
    $versionid = (int)$matches[2];
    $userid = (int)$matches[3];
    if ($documentid <= 0 || $versionid <= 0 || $userid <= 0) {
        continue;
    }

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
    ], IGNORE_MISSING);
    if (!$record || (string)$record->documenttype !== 'type1') {
        continue;
    }

    $link = $DB->get_record('sental_modeb_doc_user', [
        'documentid' => $documentid,
        'userid' => $userid,
    ], '*', IGNORE_MISSING);
    if (!$link) {
        continue;
    }

    $link->showinpublicprofile = $showinpublic;
    $link->publicprofileoverride = $showinpublic;
    $DB->update_record('sental_modeb_doc_user', $link);

    local_sentaldocupload_audit($documentid, $versionid, $userid, 'public_visibility');
    $summaryupdates[$documentid . ':' . $versionid] = [$documentid, $versionid];
    $changed++;
}

foreach ($summaryupdates as $summaryupdate) {
    local_sentaldocupload_refresh_public_profile_summary((int)$summaryupdate[0], (int)$summaryupdate[1]);
}

$transaction->allow_commit();

$target = $returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/local/sentaldocupload/history.php');
redirect($target, get_string('publicprofilevisibilitybulkupdated', 'local_sentaldocupload', $changed), 2);
