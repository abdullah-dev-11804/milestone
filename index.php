<?php
// Manual document upload page.
// Manual scan upload is available for every course. EDS profile membership only adds EDS path.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/form/filemanager.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
$requestedcourseid = optional_param('courseid', 0, PARAM_INT);
$canmanageglobal = has_capability('local/sentaldocupload:manage', $context);

// Students/learners must never see the upload permission error page.
// If a learner reaches the upload URL directly, send them to their safe
// My Certifications page instead of showing local/sentaldocupload:manage.
if (!$canmanageglobal && !local_sentaldocupload_can_upload_for_course($requestedcourseid)) {
    $params = [];
   if ($requestedcourseid > 0) {
        $params['courseid'] = $requestedcourseid;
    }
    redirect(new moodle_url('/local/sentaldocupload/mydocuments.php', $params));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/sentaldocupload/index.php', $requestedcourseid > 0 ? ['courseid' => $requestedcourseid] : []));
$PAGE->set_title(get_string('manualdocumentupload', 'local_sentaldocupload'));
$PAGE->set_heading(get_string('manualdocumentupload', 'local_sentaldocupload'));
$PAGE->requires->css(new moodle_url('/local/sentaldocupload/styles.css'));
$ajaxcoursesurl = new moodle_url('/local/sentaldocupload/ajax_courses.php', $requestedcourseid > 0 ? ['courseid' => $requestedcourseid] : []);
$ajaxexpiryurl = new moodle_url('/local/sentaldocupload/ajax_expiry.php');
$ajaxedsconflicturl = new moodle_url('/local/sentaldocupload/ajax_eds_conflict.php');
$PAGE->requires->js_call_amd('local_sentaldocupload/bulk_upload', 'init', [
    $ajaxcoursesurl->out(false),
    $ajaxexpiryurl->out(false),
    sesskey(),
    $requestedcourseid,
    [
        'edsconflictchecking' => get_string('edsconflictchecking', 'local_sentaldocupload'),
        'edsconflictcheckfailed' => get_string('edsconflictcheckfailed', 'local_sentaldocupload'),
    ],
    $ajaxedsconflicturl->out(false),
]);

$documenttypes = [
    'type1' => get_string('doctype_type1', 'local_sentaldocupload'),
    'type2' => get_string('doctype_type2', 'local_sentaldocupload'),
];

$maxrows = 5;
$filemanageroptions = [
    'subdirs' => 0,
    'maxbytes' => 10 * 1024 * 1024,
    'areamaxbytes' => 10 * 1024 * 1024,
    'maxfiles' => 1,
    'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png'],
    'return_types' => FILE_INTERNAL,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $companyid = local_sentaldocupload_get_current_company_id();
    $userid = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $issuedatestr = required_param('issuedate', PARAM_RAW_TRIMMED);

    if (empty($userid)) {
        redirect($PAGE->url, get_string('missinguser', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (empty($courseid)) {
        redirect($PAGE->url, get_string('missingcourse', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (!local_sentaldocupload_can_upload_for_course($courseid)) {
        redirect($PAGE->url, get_string('courseuploadnotallowed', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (!$canmanageglobal && $requestedcourseid > 0 && $courseid !== $requestedcourseid) {
        redirect($PAGE->url, get_string('courseuploadnotallowed', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedatestr)) {
        redirect($PAGE->url, get_string('missingissuedate', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $issuedate = strtotime($issuedatestr . ' 00:00:00');
    if (empty($issuedate)) {
        redirect($PAGE->url, get_string('missingissuedate', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);
    if ($companyid > 0 && !local_sentaldocupload_user_in_company($userid, $companyid)) {
        redirect($PAGE->url, get_string('usernotincompany', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // Security check: manual scan upload is available for ALL courses of the selected learner.
    if (!local_sentaldocupload_user_enrolled_in_course($userid, $courseid)) {
        redirect($PAGE->url, get_string('courseusernotallowed', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $usercontext = context_user::instance($USER->id);
    $fs = get_file_storage();
    $uploadrows = [];

    for ($i = 0; $i < $maxrows; $i++) {
        $documenttype = optional_param('documenttype' . $i, '', PARAM_ALPHANUMEXT);
        $customlabel = optional_param('customlabel' . $i, '', PARAM_TEXT);
        $showinpublic = optional_param('showinpublic' . $i, 0, PARAM_BOOL);
        $draftitemid = optional_param('documentfiles' . $i, 0, PARAM_INT);
        $participantids = optional_param_array('participants' . $i, [], PARAM_INT);
        $participantids = array_values(array_unique(array_filter(array_map('intval', $participantids))));
        $participantids = array_values(array_diff($participantids, [$userid]));

        if (empty($draftitemid)) {
            continue;
        }

        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'filename', false);
        if (!$draftfiles) {
            continue;
        }

        if (empty($documenttypes[$documenttype])) {
            redirect($PAGE->url, get_string('missingdoctype', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
        }

        if ($documenttype === 'type2' && trim($customlabel) === '') {
            redirect($PAGE->url, get_string('missingcustomlabel', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
        }
        if ($documenttype === 'type1' && trim($customlabel) === '') {
            $customlabel = get_string('type1defaultlabel', 'local_sentaldocupload');
        }

        foreach ($participantids as $participantid) {
            if ($companyid > 0 && !local_sentaldocupload_user_in_company($participantid, $companyid)) {
                redirect($PAGE->url, get_string('participantnotallowed', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
            }
            if (!local_sentaldocupload_user_enrolled_in_course($participantid, $courseid)) {
                redirect($PAGE->url, get_string('participantnotallowed', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        if ($documenttype === 'type1') {
            $linkeduserids = array_values(array_unique(array_merge([$userid], $participantids)));
            foreach ($linkeduserids as $linkeduserid) {
                $linkeduserid = (int)$linkeduserid;
                $activeeds = local_sentaldocupload_get_active_eds_course_completion_document($courseid, $linkeduserid);
                if (!$activeeds) {
                    continue;
                }

                $learner = $DB->get_record('user', ['id' => $linkeduserid], 'id,firstname,lastname,email,username', IGNORE_MISSING);
                $course = get_course($courseid);
                $expirytext = empty($activeeds->expirydate)
                    ? get_string('noexpiry', 'local_sentaldocupload')
                    : userdate((int)$activeeds->expirydate, get_string('strftimedate', 'langconfig'));
                redirect($PAGE->url, get_string('activeedsdocumentblocksupload', 'local_sentaldocupload', (object)[
                    'learner' => $learner ? fullname($learner) : (string)$linkeduserid,
                    'course' => format_string($course->fullname),
                    'expiry' => $expirytext,
                ]), null, \core\output\notification::NOTIFY_ERROR);
            }
        }

        foreach ($draftfiles as $storedfile) {
            if ($storedfile->is_directory()) {
                continue;
            }
            if ((int)$storedfile->get_filesize() > 10 * 1024 * 1024) {
                redirect($PAGE->url, get_string('filetoolarge', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
            }
            $filename = clean_param($storedfile->get_filename(), PARAM_FILE);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                redirect($PAGE->url, get_string('invalidfiletype', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
            }
            if ($documenttype === 'type1' && $ext !== 'pdf') {
                redirect($PAGE->url, get_string('type1mustbepdf', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
            }

            $uploadrows[] = [
                'row' => $i,
                'documenttype' => $documenttype,
                'customlabel' => trim($customlabel),
                'showinpublic' => $showinpublic ? 1 : 0,
                'draftitemid' => $draftitemid,
                'filename' => $filename,
                'storedfile' => $storedfile,
                'participantids' => $participantids,
            ];
        }
    }

    if (!$uploadrows) {
        redirect($PAGE->url, get_string('missingbulkfiles', 'local_sentaldocupload'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $validitydays = local_sentaldocupload_get_course_validity_days($courseid);
    $expirydate = local_sentaldocupload_calculate_expiry($issuedate, $validitydays);
    $now = time();
    $uploadedfiles = [];
    $draftitemids = [];

    $transaction = $DB->start_delegated_transaction();
    $doccolumns = $DB->get_columns('sental_modeb_doc');
    $versioncolumns = $DB->get_columns('sental_modeb_doc_version');

    foreach ($uploadrows as $uploadinfo) {
        $documenttype = $uploadinfo['documenttype'];
        $customlabel = $uploadinfo['customlabel'];
        $filename = $uploadinfo['filename'];
        $participantids = !empty($uploadinfo['participantids']) ? $uploadinfo['participantids'] : [];
        $linkeduserids = array_values(array_unique(array_merge([$userid], $participantids)));
        $draftitemids[] = (int)$uploadinfo['draftitemid'];

        // Version history rule:
        // - Type 1 has one course-completion document record per learner/course; every replacement becomes v2, v3...
        // - Type 2 has one supplementary document record per learner/course/custom label; uploading the same label again becomes v2, v3...
        //   Uploading a different custom label creates a separate supplementary document record.
        $existing = false;
        if ($documenttype === 'type1') {
            $sql = "SELECT d.*
                      FROM {sental_modeb_doc} d
                      JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
                     WHERE d.courseid = :courseid
                       AND d.documenttype = :documenttype
                       AND du.userid = :userid
                  ORDER BY d.id DESC";
            $existing = $DB->get_record_sql($sql, [
                'courseid' => $courseid,
                'documenttype' => $documenttype,
                'userid' => $userid,
            ], IGNORE_MULTIPLE);
        } else if ($documenttype === 'type2') {
            $sql = "SELECT d.*
                      FROM {sental_modeb_doc} d
                      JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
                     WHERE d.courseid = :courseid
                       AND d.documenttype = :documenttype
                       AND du.userid = :userid
                       AND d.customlabel = :customlabel
                  ORDER BY d.id DESC";
            $existing = $DB->get_record_sql($sql, [
                'courseid' => $courseid,
                'documenttype' => $documenttype,
                'userid' => $userid,
                'customlabel' => $customlabel,
            ], IGNORE_MULTIPLE);
        }

        // Public profile logic is stored per linked learner because one file can be linked
        // to multiple learners and EDS availability may differ per learner.
        // New EDS rule:
        // - If an EDS course-completion job/document exists in local_ncasign_jobs,
        //   hide the Show in Public checkbox in the UI and force the scan to stay private.
        // - If no EDS job/document exists, Type 1 can be public when the checkbox is checked.
        $publicprofilebyuserid = [];
        $publicprofileoverridebyuserid = [];
        foreach ($linkeduserids as $linkeduserid) {
            $linkeduserid = (int)$linkeduserid;
            $hasedsdocument = local_sentaldocupload_user_course_has_eds_document($courseid, $linkeduserid);
            $allowpubliccheckbox = ($documenttype === 'type1' && !$hasedsdocument);
            $adminselectedpublic = ($allowpubliccheckbox && !empty($uploadinfo['showinpublic']));

            $publicprofileoverridebyuserid[$linkeduserid] = $adminselectedpublic ? 1 : 0;
            $publicprofilebyuserid[$linkeduserid] = local_sentaldocupload_should_show_scan_in_public_profile(
                $documenttype,
                $courseid,
                $linkeduserid,
                $adminselectedpublic
            );
        }
        // Keep the document/version-level field as a current-state summary for backward compatibility.
        // Public Profile display is recalculated dynamically by local_sentaldocupload_get_public_profile_scans().
        $showinpublicprofile = !empty($publicprofilebyuserid) ? max($publicprofilebyuserid) : 0;
        $publicprofileoverride = !empty($publicprofileoverridebyuserid) ? max($publicprofileoverridebyuserid) : 0;

        if ($existing) {
            $documentid = (int)$existing->id;
            $versionno = local_sentaldocupload_get_next_version_number($documentid);
            $action = 'replace';
            $updaterecord = (object)[
                'id' => $documentid,
                'issuedate' => $issuedate,
                'expirydate' => $expirydate,
                'validitydays' => $validitydays,
                'currentversion' => $versionno,
                'timemodified' => $now,
            ];
            foreach ([
                'customlabel' => $customlabel,
                'showinpublicprofile' => $showinpublicprofile,
                'publicprofileoverride' => $publicprofileoverride,
            ] as $field => $value) {
                if (isset($doccolumns[$field])) {
                    $updaterecord->$field = $value;
                }
            }
            $DB->update_record('sental_modeb_doc', $updaterecord);
        } else {
            $docrecord = (object)[
                'courseid' => $courseid,
                'documenttype' => $documenttype,
                'issuedate' => $issuedate,
                'expirydate' => $expirydate,
                'validitydays' => $validitydays,
                'currentversion' => 0,
                'createdby' => $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            foreach ([
                'customlabel' => $customlabel,
                'showinpublicprofile' => $showinpublicprofile,
                'publicprofileoverride' => $publicprofileoverride,
                'autocompleted' => 0,
                'completiontime' => 0,
            ] as $field => $value) {
                if (isset($doccolumns[$field])) {
                    $docrecord->$field = $value;
                }
            }
            $documentid = $DB->insert_record('sental_modeb_doc', $docrecord);
            $versionno = 1;
            $action = 'upload';
        }

        $versionrecord = (object)[
            'documentid' => $documentid,
            'versionno' => $versionno,
            'filename' => $filename,
            'uploadedby' => $USER->id,
            'timecreated' => $now,
        ];
        foreach ([
            'issuedate' => $issuedate,
            'expirydate' => $expirydate,
            'validitydays' => $validitydays,
            'customlabel' => $customlabel,
            'showinpublicprofile' => $showinpublicprofile,
            'publicprofileoverride' => $publicprofileoverride,
        ] as $field => $value) {
            if (isset($versioncolumns[$field])) {
                $versionrecord->$field = $value;
            }
        }

        $versionid = $DB->insert_record('sental_modeb_doc_version', $versionrecord);

        $fileinfo = [
            'contextid' => $context->id,
            'component' => 'local_sentaldocupload',
            'filearea' => 'document',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $fs->delete_area_files($context->id, 'local_sentaldocupload', 'document', $versionid);
        $fs->create_file_from_storedfile($fileinfo, $uploadinfo['storedfile']);

        // Keep the main document record pointing at the latest version.
        $DB->update_record('sental_modeb_doc', (object)[
            'id' => $documentid,
            'currentversion' => $versionno,
            'timemodified' => $now,
        ]);

        $autocompletedcount = 0;
        $autocompletedbyuserid = [];
        $completiontimebyuserid = [];
        if ($documenttype === 'type1') {
            foreach ($linkeduserids as $linkeduserid) {
                $linkeduserid = (int)$linkeduserid;
                $autocompletedbyuserid[$linkeduserid] = 0;
                $completiontimebyuserid[$linkeduserid] = 0;
                if (local_sentaldocupload_mark_course_completed($courseid, $linkeduserid, $now, $versionid)) {
                    $autocompletedcount++;
                    $autocompletedbyuserid[$linkeduserid] = 1;
                    $completiontimebyuserid[$linkeduserid] = $now;
                    local_sentaldocupload_audit($documentid, $versionid, $linkeduserid, 'course_completed');
                }
            }
            if ($autocompletedcount > 0) {
                $completionupdate = (object)['id' => $documentid];
                if (isset($doccolumns['autocompleted'])) {
                    $completionupdate->autocompleted = 1;
                }
                if (isset($doccolumns['completiontime'])) {
                    $completionupdate->completiontime = $now;
                }
                if (count((array)$completionupdate) > 1) {
                    $DB->update_record('sental_modeb_doc', $completionupdate);
                }
            }
        }

        foreach ($linkeduserids as $linkeduserid) {
            $linkeduserid = (int)$linkeduserid;
            local_sentaldocupload_link_document_user(
                $documentid,
                $linkeduserid,
                $now,
                (int)($publicprofilebyuserid[$linkeduserid] ?? 0),
                (int)($autocompletedbyuserid[$linkeduserid] ?? 0),
                (int)($completiontimebyuserid[$linkeduserid] ?? 0),
                (int)($publicprofileoverridebyuserid[$linkeduserid] ?? 0)
            );
            local_sentaldocupload_audit($documentid, $versionid, $linkeduserid, $action);
        }

        $participantcount = max(0, count($linkeduserids) - 1);
        $extrasuffix = $participantcount ? ' + ' . $participantcount . ' participant(s)' : '';
        $completionnote = $autocompletedcount ? ' - ' . $autocompletedcount . ' auto-completed' : '';
        $uploadedfiles[] = s($filename) . ' - ' . s($documenttypes[$documenttype]) . $extrasuffix . $completionnote . ' (v' . $versionno . ')';
    }

    $transaction->allow_commit();

    foreach (array_unique($draftitemids) as $draftitemid) {
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
    }

    redirect($PAGE->url, get_string('bulkdocumentsuploaded', 'local_sentaldocupload', count($uploadedfiles)), null, \core\output\notification::NOTIFY_SUCCESS);
}

$selectedcompanyid = local_sentaldocupload_get_current_company_id();
$userrecords = local_sentaldocupload_get_upload_users($selectedcompanyid);

// Output page.
echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/sentaldocupload/history.php'), get_string('historylink', 'local_sentaldocupload'), ['class' => 'btn btn-secondary mr-2']) . ' ' .
    html_writer::link(new moodle_url('/local/sentaldocupload/audit.php'), get_string('audittrail', 'local_sentaldocupload'), ['class' => 'btn btn-secondary']),
    'mb-3'
);
echo html_writer::tag('p', get_string('manualuploaddescription', 'local_sentaldocupload'), ['class' => 'alert alert-info']);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'enctype' => 'multipart/form-data',
    'id' => 'sental-modeb-bulk-upload-form',
    'class' => 'sental-modeb-upload-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'companyid',
    'id' => 'id_companyid',
    'value' => $selectedcompanyid ?: 0,
]);

echo html_writer::start_div('form-group mb-3');
echo html_writer::tag('label', get_string('selectuser', 'local_sentaldocupload'), ['for' => 'id_usercombo']);
echo html_writer::start_div('sental-user-combo', ['id' => 'id_usercombo_wrap']);
echo html_writer::empty_tag('input', [
    'type' => 'search',
    'id' => 'id_usercombo',
    'class' => 'form-control sental-user-combo-input',
    'placeholder' => get_string('searchuserplaceholder', 'local_sentaldocupload'),
    'autocomplete' => 'off',
    'aria-autocomplete' => 'list',
    'aria-controls' => 'id_user_dropdown',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'id' => 'id_userid', 'value' => '']);
echo html_writer::start_div('sental-user-dropdown', ['id' => 'id_user_dropdown', 'hidden' => 'hidden']);
foreach ($userrecords as $user) {
    $label = $user->fullname;
    if (!empty($user->email)) {
        $label .= ' (' . $user->email . ')';
    }
    echo html_writer::tag('button', s($label), [
        'type' => 'button',
        'class' => 'sental-user-option',
        'data-userid' => (int)$user->id,
        'data-companyids' => s($user->companyids),
        'data-search' => s(core_text::strtolower($label)),
        'data-label' => s($label),
    ]);
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('small', get_string('usersearchhelp', 'local_sentaldocupload'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-3');
echo html_writer::tag('label', get_string('selectcourse', 'local_sentaldocupload'), ['for' => 'id_courseid']);
echo html_writer::select(['' => get_string('selectcourseafteruser', 'local_sentaldocupload')], 'courseid', '', false, ['id' => 'id_courseid', 'class' => 'form-control custom-select', 'disabled' => 'disabled']);
echo html_writer::tag('small', get_string('allcourseshelp', 'local_sentaldocupload'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-3');
echo html_writer::tag('label', get_string('issuedate', 'local_sentaldocupload'), ['for' => 'id_issuedate']);
echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'issuedate', 'id' => 'id_issuedate', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::div(get_string('expirypreviewpending', 'local_sentaldocupload'), 'alert alert-info', ['id' => 'id_expirypreview', 'role' => 'status']);
echo html_writer::div('', 'alert alert-warning', [
    'id' => 'id_eds_conflict_warning',
    'role' => 'alert',
    'style' => 'display:none;',
    'aria-hidden' => 'true',
]);

echo html_writer::start_div('sental-document-upload-section mt-4');
echo html_writer::tag('h4', get_string('documentfiles', 'local_sentaldocupload'));
echo html_writer::tag('p', get_string('rowfilehelp', 'local_sentaldocupload'), ['class' => 'text-muted']);

echo html_writer::start_div('sental-document-rows', ['id' => 'id_document_rows']);
for ($i = 0; $i < $maxrows; $i++) {
    $rowattrs = [
        'class' => 'sental-document-row card mb-3',
        'data-row' => $i,
        'id' => 'id_document_row_' . $i,
    ];
    if ($i > 0) {
        $rowattrs['style'] = 'display:none;';
        $rowattrs['aria-hidden'] = 'true';
    }

    echo html_writer::start_div('', $rowattrs);
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', get_string('documentuploadrow', 'local_sentaldocupload', $i + 1), ['class' => 'card-title']);

    echo html_writer::start_div('form-group mb-3');
    echo html_writer::tag('label', get_string('documenttype', 'local_sentaldocupload'), ['for' => 'id_documenttype' . $i]);
    echo html_writer::select($documenttypes, 'documenttype' . $i, 'type1', false, [
        'id' => 'id_documenttype' . $i,
        'class' => 'form-control custom-select sental-row-documenttype',
        'data-row' => $i,
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group mb-3 sental-customlabel-wrap', [
        'id' => 'id_customlabel_wrap' . $i,
        'data-row' => $i,
        'style' => 'display:none;',
    ]);
    echo html_writer::tag('label', get_string('customlabel', 'local_sentaldocupload'), ['for' => 'id_customlabel' . $i]);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'customlabel' . $i,
        'id' => 'id_customlabel' . $i,
        'class' => 'form-control sental-customlabel-input',
        'placeholder' => get_string('customlabelplaceholder', 'local_sentaldocupload'),
    ]);
    echo html_writer::tag('small', get_string('customlabelhelp', 'local_sentaldocupload'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group mb-3 sental-publicprofile-wrap', [
        'id' => 'id_showpublic_wrap' . $i,
        'data-row' => $i,
    ]);
    echo html_writer::start_div('form-check');
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'showinpublic' . $i,
        'id' => 'id_showinpublic' . $i,
        'class' => 'form-check-input sental-showpublic-input',
        'value' => 1,
    ]);
    echo html_writer::tag('label', get_string('showinpublicprofile', 'local_sentaldocupload'), [
        'for' => 'id_showinpublic' . $i,
        'class' => 'form-check-label',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('small', get_string('showinpublicprofilehelp', 'local_sentaldocupload'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group mb-3 sental-participants-wrap', [
        'id' => 'id_participants_wrap' . $i,
        'data-row' => $i,
    ]);
    echo html_writer::tag('label', get_string('additionalparticipants', 'local_sentaldocupload'), ['for' => 'id_participant_combo_input' . $i]);
    echo html_writer::start_div('sental-participant-combo', [
        'id' => 'id_participant_combo' . $i,
        'data-row' => $i,
    ]);
    echo html_writer::start_div('sental-participant-combo-control', ['id' => 'id_participant_combo_control' . $i]);
    echo html_writer::span('', 'sental-participant-selected', ['id' => 'id_participant_selected' . $i]);
    echo html_writer::empty_tag('input', [
        'type' => 'search',
        'class' => 'sental-participant-combo-input',
        'id' => 'id_participant_combo_input' . $i,
        'data-row' => $i,
        'placeholder' => get_string('searchparticipantsplaceholder', 'local_sentaldocupload'),
        'autocomplete' => 'off',
    ]);
    echo html_writer::end_div();
    echo html_writer::div(get_string('selectcoursefirstparticipants', 'local_sentaldocupload'), 'sental-participant-dropdown', [
        'id' => 'id_participant_dropdown' . $i,
        'data-row' => $i,
        'hidden' => 'hidden',
    ]);
    echo html_writer::div('', 'sental-participant-hidden-inputs', ['id' => 'id_participant_hidden' . $i]);
    echo html_writer::end_div();
    echo html_writer::tag('small', get_string('additionalparticipantshelp', 'local_sentaldocupload'), ['class' => 'form-text text-muted']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group mb-3 sental-filemanager-wrap');
    echo html_writer::tag('label', get_string('documentfile', 'local_sentaldocupload'), ['for' => 'id_documentfiles' . $i]);
    $draftitemid = file_get_unused_draft_itemid();
    file_prepare_draft_area($draftitemid, $context->id, 'local_sentaldocupload', 'document', 0, $filemanageroptions);
    $filemanagerelement = new MoodleQuickForm_filemanager(
        'documentfiles' . $i,
        get_string('documentfile', 'local_sentaldocupload'),
        ['id' => 'id_documentfiles' . $i],
        $filemanageroptions
    );
    $filemanagerelement->setValue($draftitemid);
    echo html_writer::div($filemanagerelement->toHtml(), 'sental-filemanager-field', ['id' => 'id_documentfiles_wrap' . $i]);
    echo html_writer::end_div();

    if ($i > 0) {
        echo html_writer::tag('button', get_string('removedocumentrow', 'local_sentaldocupload'), [
            'type' => 'button',
            'class' => 'btn btn-outline-danger btn-sm sental-remove-document-row',
            'data-row' => $i,
        ]);
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::tag('button', get_string('addanotherdocument', 'local_sentaldocupload'), [
    'type' => 'button',
    'id' => 'id_add_document_row',
    'class' => 'btn btn-secondary mb-4',
]);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'submitbutton',
    'id' => 'id_submitbutton',
    'class' => 'btn btn-primary',
    'value' => get_string('uploadselecteddocuments', 'local_sentaldocupload'),
    'disabled' => 'disabled',
]);

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
