<?php
// Admin version history for manual document uploads.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/sentaldocupload:manage', $context);

$page = optional_param('page', 0, PARAM_INT);
$studentid = optional_param('studentid', 0, PARAM_INT);
$studentq = trim(optional_param('studentq', '', PARAM_TEXT));
$courseid = optional_param('courseid', 0, PARAM_INT);
$doctype = optional_param('doctype', '', PARAM_ALPHANUMEXT);
$ajax = optional_param('ajax', 0, PARAM_BOOL);
$perpage = 25;

$urlparams = [];
if ($studentid > 0) {
    $urlparams['studentid'] = $studentid;
}
if ($studentq !== '') {
    $urlparams['studentq'] = $studentq;
}
if ($courseid > 0) {
    $urlparams['courseid'] = $courseid;
}
if ($doctype !== '') {
    $urlparams['doctype'] = $doctype;
}
$pageurl = new moodle_url('/local/sentaldocupload/history.php', $urlparams);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('versionhistory', 'local_sentaldocupload'));
$PAGE->set_heading(get_string('versionhistory', 'local_sentaldocupload'));
$PAGE->requires->css(new moodle_url('/local/sentaldocupload/styles.css'));

$documenttypes = [
    'type1' => get_string('coursecompletiondocument', 'local_sentaldocupload'),
    'type2' => 'Supplementary document',
    'protocol' => get_string('protocol', 'local_sentaldocupload'),
    'certificate' => get_string('certificate', 'local_sentaldocupload'),
    'completionbook' => get_string('completionbook', 'local_sentaldocupload'),
];

function local_sentaldocupload_history_document_type_label(string $type, ?string $customlabel = null, array $documenttypes = []): string {
    if ($type === 'type1') {
        return 'Course completion';
    }
    if ($type === 'type2') {
        $label = 'Supplementary document';
        if (!empty($customlabel)) {
            $label .= ' - ' . $customlabel;
        }
        return $label;
    }
    return $documenttypes[$type] ?? ucfirst($type);
}

function local_sentaldocupload_history_filter_select($name, array $options, $selected, $alllabel) {
    $html = html_writer::start_tag('select', ['name' => $name, 'class' => 'form-control sental-filter-control']);
    $html .= html_writer::tag('option', $alllabel, ['value' => '']);
    foreach ($options as $value => $label) {
        $attrs = ['value' => $value];
        if ((string)$selected === (string)$value) {
            $attrs['selected'] = 'selected';
        }
        $html .= html_writer::tag('option', s($label), $attrs);
    }
    $html .= html_writer::end_tag('select');
    return $html;
}

function local_sentaldocupload_history_build_where(int $studentid, string $studentq, int $courseid, string $doctype, array $documenttypes): array {
    global $DB;
    $where = [];
    $params = [];

    if ($studentid > 0) {
        $where[] = 'du.userid = :filterstudentid';
        $params['filterstudentid'] = $studentid;
    } else if ($studentq !== '') {
        $studentneedle = $studentq;
        if (preg_match('/\(([^)]+)\)\s*$/', $studentneedle, $matches)) {
            $studentneedle = $matches[1];
        }
        $like = '%' . $DB->sql_like_escape($studentneedle) . '%';
        $where[] = '(' .
            $DB->sql_like('learner.firstname', ':qlearnerfirst', false) . ' OR ' .
            $DB->sql_like('learner.lastname', ':qlearnerlast', false) . ' OR ' .
            $DB->sql_like('learner.email', ':qlearneremail', false) .
        ')';
        $params += [
            'qlearnerfirst' => $like,
            'qlearnerlast' => $like,
            'qlearneremail' => $like,
        ];
    }

    if ($courseid > 0) {
        $where[] = 'd.courseid = :courseid';
        $params['courseid'] = $courseid;
    }

    if ($doctype !== '' && array_key_exists($doctype, $documenttypes)) {
        $where[] = 'd.documenttype = :doctype';
        $params['doctype'] = $doctype;
    }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}


function local_sentaldocupload_history_shorten_filename(string $filename, int $max = 38): string {
    $filename = trim($filename);
    if ($filename === '' || $filename === '-') {
        return '-';
    }
    if (core_text::strlen($filename) <= $max) {
        return $filename;
    }
    $extension = '';
    $dotpos = core_text::strrpos($filename, '.');
    if ($dotpos !== false && $dotpos > 0) {
        $extension = core_text::substr($filename, $dotpos);
    }
    $keep = max(10, $max - core_text::strlen($extension) - 3);
    return core_text::substr($filename, 0, $keep) . '...' . $extension;
}

function local_sentaldocupload_history_version_payload(stdClass $version): array {
    $viewurl = new moodle_url('/local/sentaldocupload/view.php', ['versionid' => $version->versionid]);
    $statuspayload = local_sentaldocupload_history_status_payload(!empty($version->expirydate) ? (int)$version->expirydate : null, true);
    return [
        'versionid' => (int)$version->versionid,
        'label' => 'v' . (int)$version->versionno,
        'versionno' => (int)$version->versionno,
        'viewurl' => $viewurl->out(false),
        'filename' => (string)($version->filename ?: '-'),
        'shortfilename' => local_sentaldocupload_history_shorten_filename((string)($version->filename ?: '-')),
        'issuedate' => !empty($version->issuedate) ? userdate((int)$version->issuedate, get_string('strftimedate', 'core_langconfig')) : '-',
        'expirydate' => !empty($version->expirydate) ? userdate((int)$version->expirydate, get_string('strftimedate', 'core_langconfig')) : get_string('noexpiry', 'local_sentaldocupload'),
        'status' => $statuspayload['status'],
        'statustext' => $statuspayload['statustext'],
        'statusclass' => $statuspayload['statusclass'],
        'uploadedby' => (string)($version->uploadername ?: '-'),
        'uploadedat' => !empty($version->timecreated) ? userdate((int)$version->timecreated) : '-',
    ];
}

function local_sentaldocupload_history_file_link(array $payload): string {
    if (empty($payload['viewurl'])) {
        return '-';
    }
    $filename = (string)($payload['filename'] ?? '-');
    $shortname = (string)($payload['shortfilename'] ?? local_sentaldocupload_history_shorten_filename($filename, 30));
    $text = html_writer::span(s($shortname), 'sental-version-filename');
    return html_writer::link($payload['viewurl'], $text, [
        'class' => 'sental-file-link sental-file-name-only',
        'target' => '_blank',
        'title' => s($filename),
    ]);
}


function local_sentaldocupload_history_status_payload(?int $expirydate, bool $hasdocument = true): array {
    $status = local_sentaldocupload_get_status($expirydate, $hasdocument);
    $map = [
        'active' => ['text' => get_string('statusactive', 'local_sentaldocupload'), 'class' => 'success'],
        'expiring' => ['text' => get_string('statusexpiring', 'local_sentaldocupload'), 'class' => 'warning'],
        'expired' => ['text' => get_string('statusexpired', 'local_sentaldocupload'), 'class' => 'danger'],
        'nodocument' => ['text' => get_string('statusnodocument', 'local_sentaldocupload'), 'class' => 'secondary'],
    ];
    $item = $map[$status] ?? $map['nodocument'];
    return [
        'status' => $status,
        'statustext' => $item['text'],
        'statusclass' => $item['class'],
    ];
}

function local_sentaldocupload_history_status_badge(array $payload): string {
    $class = clean_param($payload['statusclass'] ?? 'secondary', PARAM_ALPHANUMEXT);
    $text = $payload['statustext'] ?? get_string('statusnodocument', 'local_sentaldocupload');
    return html_writer::span(s($text), 'badge badge-' . $class . ' sental-cert-status sental-cert-status-' . s($payload['status'] ?? 'nodocument'));
}

function local_sentaldocupload_history_render_results(int $page, int $perpage, moodle_url $pageurl, int $studentid, string $studentq, int $courseid, string $doctype, array $documenttypes): string {
    global $DB, $OUTPUT;

    [$whereclause, $params] = local_sentaldocupload_history_build_where($studentid, $studentq, $courseid, $doctype, $documenttypes);

    $fromsql = "
          FROM {sental_modeb_doc} d
          JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
          JOIN {user} learner ON learner.id = du.userid
          JOIN {course} c ON c.id = d.courseid
    ";

    $countsql = 'SELECT COUNT(1) FROM (SELECT du.userid, d.courseid ' . $fromsql . $whereclause . ' GROUP BY du.userid, d.courseid) groupedrows';
    $total = (int)$DB->count_records_sql($countsql, $params);

    $groupsql = "SELECT CONCAT(du.userid, '-', d.courseid) AS rowkey,
                        du.userid AS learnerid,
                        d.courseid,
                        c.fullname AS coursename,
                        learner.firstname AS learnerfirstname,
                        learner.lastname AS learnerlastname,
                        learner.email AS learneremail
" . $fromsql . $whereclause . "
          GROUP BY du.userid, d.courseid, c.fullname, learner.firstname, learner.lastname, learner.email
          ORDER BY c.fullname ASC, learner.lastname ASC, learner.firstname ASC";
    $rows = $DB->get_records_sql($groupsql, $params, $page * $perpage, $perpage);

    $html = html_writer::start_div('sental-ajax-results-inner');
    $html .= html_writer::tag('p', get_string('pagination_summary', 'local_sentaldocupload', (object)[
        'total' => $total,
        'perpage' => $perpage,
    ]), ['class' => 'text-muted']);

    if (!$rows) {
        $html .= $OUTPUT->notification(get_string('noversionhistory', 'local_sentaldocupload'), core\output\notification::NOTIFY_INFO);
        $html .= html_writer::end_div();
        return $html;
    }

    $learnerids = array_values(array_unique(array_map(static function($row) { return (int)$row->learnerid; }, $rows)));
    $courseids = array_values(array_unique(array_map(static function($row) { return (int)$row->courseid; }, $rows)));
    [$userinsql, $userparams] = $DB->get_in_or_equal($learnerids, SQL_PARAMS_NAMED, 'huserid');
    [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'hcourseid');

    $docparams = $userparams + $courseparams;
    $docwhere = "du.userid $userinsql AND d.courseid $courseinsql";
    if ($doctype !== '' && array_key_exists($doctype, $documenttypes)) {
        $docwhere .= ' AND d.documenttype = :docdoctype';
        $docparams['docdoctype'] = $doctype;
    }

    $docsql = "SELECT d.id AS documentid,
                      du.userid AS learnerid,
                      d.courseid,
                      d.documenttype,
                      d.customlabel,
                      d.currentversion,
                      d.issuedate,
                      d.expirydate
                 FROM {sental_modeb_doc} d
                 JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
                WHERE $docwhere
             ORDER BY d.courseid ASC, du.userid ASC,
                      CASE WHEN d.documenttype = 'type1' THEN 0 WHEN d.documenttype = 'type2' THEN 1 ELSE 2 END,
                      d.customlabel ASC,
                      d.id DESC";
    $documents = $DB->get_records_sql($docsql, $docparams);

    $docsbygroup = [];
    $docids = [];
    foreach ($documents as $doc) {
        $key = (int)$doc->learnerid . '-' . (int)$doc->courseid;
        $docsbygroup[$key][] = $doc;
        $docids[] = (int)$doc->documentid;
    }

    $versionsbydoc = [];
    if ($docids) {
        [$docinsql, $docinparams] = $DB->get_in_or_equal(array_values(array_unique($docids)), SQL_PARAMS_NAMED, 'docid');
        $versionsql = "SELECT v.id AS versionid,
                              v.documentid,
                              v.versionno,
                              v.filename,
                              v.issuedate,
                              v.expirydate,
                              v.timecreated,
                              up.firstname AS uploaderfirstname,
                              up.lastname AS uploaderlastname
                         FROM {sental_modeb_doc_version} v
                    LEFT JOIN {user} up ON up.id = v.uploadedby
                        WHERE v.documentid $docinsql
                     ORDER BY v.documentid ASC, v.versionno DESC";
        $versionrecords = $DB->get_records_sql($versionsql, $docinparams);
        foreach ($versionrecords as $version) {
            $version->uploadername = trim(fullname((object)[
                'firstname' => $version->uploaderfirstname ?? '',
                'lastname' => $version->uploaderlastname ?? '',
            ]));
            $versionsbydoc[(int)$version->documentid][] = $version;
        }
    }

    $docdata = [];
    foreach ($documents as $doc) {
        $versions = $versionsbydoc[(int)$doc->documentid] ?? [];
        $versionpayloads = [];
        $selectedpayload = null;
        foreach ($versions as $version) {
            $payload = local_sentaldocupload_history_version_payload($version);
            $versionpayloads[] = $payload;
            if ((int)$version->versionno === (int)$doc->currentversion) {
                $selectedpayload = $payload;
            }
        }
        if (!$selectedpayload && $versionpayloads) {
            $selectedpayload = reset($versionpayloads);
        }
        $docdata[(int)$doc->documentid] = [
            'documentid' => (int)$doc->documentid,
            'type' => (string)$doc->documenttype,
            'label' => local_sentaldocupload_history_document_type_label((string)$doc->documenttype, $doc->customlabel ?? '', $documenttypes),
            'currentversion' => (int)$doc->currentversion,
            'versions' => $versionpayloads,
            'selectedversionid' => $selectedpayload['versionid'] ?? 0,
        ];
    }

    $table = new html_table();
    $table->head = [
        get_string('course', 'local_sentaldocupload'),
        get_string('learner', 'local_sentaldocupload'),
        get_string('documenttype', 'local_sentaldocupload'),
        get_string('versionno', 'local_sentaldocupload'),
        get_string('issuedate', 'local_sentaldocupload'),
        get_string('expirydate', 'local_sentaldocupload'),
        get_string('certificationstatus', 'local_sentaldocupload'),
        get_string('uploadedby', 'local_sentaldocupload'),
        get_string('uploadedat', 'local_sentaldocupload'),
        get_string('file', 'local_sentaldocupload'),
    ];
    $table->attributes['class'] = 'generaltable sental-version-history sental-version-history-compact sental-history-v055';

    foreach ($rows as $row) {
        $key = (int)$row->learnerid . '-' . (int)$row->courseid;
        $documentsforrow = $docsbygroup[$key] ?? [];
        if (!$documentsforrow) {
            continue;
        }

        $selecteddoc = reset($documentsforrow);
        $selecteddocdata = $docdata[(int)$selecteddoc->documentid] ?? null;
        $selectedversion = null;
        if (!empty($selecteddocdata['versions'])) {
            foreach ($selecteddocdata['versions'] as $payload) {
                if ((int)$payload['versionid'] === (int)$selecteddocdata['selectedversionid']) {
                    $selectedversion = $payload;
                    break;
                }
            }
            if (!$selectedversion) {
                $selectedversion = reset($selecteddocdata['versions']);
            }
        }

        $docselect = html_writer::start_tag('select', ['class' => 'form-control sental-doc-select', 'aria-label' => get_string('documenttype', 'local_sentaldocupload')]);
        foreach ($documentsforrow as $doc) {
            $attrs = ['value' => (int)$doc->documentid];
            if ((int)$selecteddoc->documentid === (int)$doc->documentid) {
                $attrs['selected'] = 'selected';
            }
            $docselect .= html_writer::tag('option', s(local_sentaldocupload_history_document_type_label((string)$doc->documenttype, $doc->customlabel ?? '', $documenttypes)), $attrs);
        }
        $docselect .= html_writer::end_tag('select');

        $versionselect = html_writer::start_tag('select', ['class' => 'form-control sental-version-select', 'aria-label' => get_string('versionno', 'local_sentaldocupload')]);
        if (!empty($selecteddocdata['versions'])) {
            foreach ($selecteddocdata['versions'] as $payload) {
                $attrs = [
                    'value' => (int)$payload['versionid'],
                    'data-viewurl' => $payload['viewurl'] ?? '',
                    'data-filename' => $payload['filename'] ?? '-',
                    'data-shortfilename' => $payload['shortfilename'] ?? ($payload['filename'] ?? '-'),
                    'data-issuedate' => $payload['issuedate'] ?? '-',
                    'data-expirydate' => $payload['expirydate'] ?? '-',
                    'data-status' => $payload['status'] ?? 'nodocument',
                    'data-statustext' => $payload['statustext'] ?? get_string('statusnodocument', 'local_sentaldocupload'),
                    'data-statusclass' => $payload['statusclass'] ?? 'secondary',
                    'data-uploadedby' => $payload['uploadedby'] ?? '-',
                    'data-uploadedat' => $payload['uploadedat'] ?? '-',
                ];
                if ($selectedversion && (int)$selectedversion['versionid'] === (int)$payload['versionid']) {
                    $attrs['selected'] = 'selected';
                }
                $versionselect .= html_writer::tag('option', s($payload['label']), $attrs);
            }
        }
        $versionselect .= html_writer::end_tag('select');

        $courseurl = new moodle_url('/course/view.php', ['id' => (int)$row->courseid]);
        $coursecell = html_writer::link($courseurl, s($row->coursename), ['class' => 'sental-table-mainlink']);

        $learnername = fullname((object)[
            'firstname' => $row->learnerfirstname ?? '',
            'lastname' => $row->learnerlastname ?? '',
        ]);
        $learnerurl = new moodle_url('/user/profile.php', ['id' => (int)$row->learnerid]);
        $learner = html_writer::link($learnerurl, s($learnername), ['class' => 'sental-table-mainlink']);
        if (!empty($row->learneremail)) {
            $learner .= html_writer::empty_tag('br') . html_writer::span(s($row->learneremail), 'text-muted small');
        }

        $issuedate = $selectedversion['issuedate'] ?? '-';
        $expirydate = $selectedversion['expirydate'] ?? get_string('noexpiry', 'local_sentaldocupload');
        $statuscell = $selectedversion ? local_sentaldocupload_history_status_badge($selectedversion) : local_sentaldocupload_history_status_badge(local_sentaldocupload_history_status_payload(null, false));
        $uploader = $selectedversion['uploadedby'] ?? '-';
        $uploadedat = $selectedversion['uploadedat'] ?? '-';
        $filecell = $selectedversion ? local_sentaldocupload_history_file_link($selectedversion) : '-';

        $table->data[] = [
            $coursecell,
            $learner,
            $docselect,
            $versionselect,
            html_writer::span($issuedate, 'sental-version-issuedate'),
            html_writer::span($expirydate, 'sental-version-expirydate'),
            html_writer::span($statuscell, 'sental-version-status'),
            html_writer::span(s($uploader), 'sental-version-uploadedby'),
            html_writer::span($uploadedat, 'sental-version-uploadedat'),
            html_writer::span($filecell, 'sental-version-filecell'),
        ];
    }

    $html .= html_writer::div(html_writer::table($table), 'sental-history-table-wrap');
    $html .= html_writer::tag('script', json_encode($docdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
        'type' => 'application/json',
        'class' => 'sental-history-doc-data',
    ]);
    $html .= $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
    $html .= html_writer::end_div();
    return $html;
}

$courses = $DB->get_records_sql_menu("SELECT DISTINCT c.id, c.fullname
                                        FROM {course} c
                                        JOIN {sental_modeb_doc} d ON d.courseid = c.id
                                    ORDER BY c.fullname ASC");

$learners = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                                    FROM {user} u
                                    JOIN {sental_modeb_doc_user} du ON du.userid = u.id
                                ORDER BY u.lastname ASC, u.firstname ASC, u.email ASC");

$availabletypes = $DB->get_records_sql_menu("SELECT DISTINCT documenttype, documenttype AS label
                                               FROM {sental_modeb_doc}
                                           ORDER BY documenttype ASC");
$filterdocumenttypes = [];
foreach ($availabletypes as $type => $ignored) {
    $filterdocumenttypes[$type] = local_sentaldocupload_history_document_type_label((string)$type, '', $documenttypes);
}
if (!$filterdocumenttypes) {
    $filterdocumenttypes = $documenttypes;
}

$courseoptions = [];
foreach ($courses as $id => $name) {
    $courseoptions[(string)$id] = $name;
}

$studentoptionshtml = '';
$selectedstudentlabel = $studentq;
foreach ($learners as $learner) {
    $label = fullname($learner) . (!empty($learner->email) ? ' (' . $learner->email . ')' : '');
    if ($studentid > 0 && (int)$studentid === (int)$learner->id) {
        $selectedstudentlabel = $label;
    }
    $studentoptionshtml .= html_writer::tag('button', s($label), [
        'type' => 'button',
        'class' => 'sental-user-option sental-filter-student-option',
        'data-userid' => (int)$learner->id,
        'data-search' => s(core_text::strtolower($label)),
        'data-label' => s($label),
    ]);
}

$filterhtml = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/sentaldocupload/history.php'))->out(false),
    'class' => 'sental-filter-form mb-3',
    'data-sental-ajax-filter' => '1',
    'onsubmit' => 'return false;',
]);
$filterhtml .= html_writer::start_div('row');
$filterhtml .= html_writer::div(
    html_writer::label(get_string('filter_student', 'local_sentaldocupload'), 'id_studentq') .
    html_writer::start_div('sental-user-combo sental-filter-student-combo') .
    html_writer::empty_tag('input', [
        'type' => 'search',
        'id' => 'id_studentq',
        'name' => 'studentq',
        'value' => s($selectedstudentlabel),
        'class' => 'form-control sental-user-combo-input sental-filter-student-input',
        'autocomplete' => 'off',
        'placeholder' => get_string('filter_student_placeholder', 'local_sentaldocupload'),
        'aria-autocomplete' => 'list',
        'aria-controls' => 'sental_history_student_dropdown',
    ]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'studentid', 'id' => 'id_studentid', 'value' => $studentid > 0 ? $studentid : '']) .
    html_writer::start_div('sental-user-dropdown sental-filter-student-dropdown', ['id' => 'sental_history_student_dropdown', 'hidden' => 'hidden']) .
    $studentoptionshtml .
    html_writer::end_div() .
    html_writer::end_div(),
    'col-md-4 mb-2'
);
$filterhtml .= html_writer::div(
    html_writer::label(get_string('course', 'local_sentaldocupload'), 'id_courseid') .
    local_sentaldocupload_history_filter_select('courseid', $courseoptions, $courseid, get_string('filter_all_courses', 'local_sentaldocupload')),
    'col-md-4 mb-2'
);
$filterhtml .= html_writer::div(
    html_writer::label(get_string('documenttype', 'local_sentaldocupload'), 'id_doctype') .
    local_sentaldocupload_history_filter_select('doctype', $filterdocumenttypes, $doctype, get_string('filter_all_document_types', 'local_sentaldocupload')),
    'col-md-4 mb-2'
);
$filterhtml .= html_writer::end_div();
$filterhtml .= html_writer::div(
    html_writer::tag('button', get_string('filter_apply', 'local_sentaldocupload'), ['type' => 'button', 'id' => 'sental_history_apply_filters', 'class' => 'btn btn-primary mr-2']) .
    html_writer::link(new moodle_url('/local/sentaldocupload/history.php'), get_string('filter_reset', 'local_sentaldocupload'), ['class' => 'btn btn-secondary sental-filter-reset']),
    'mt-2'
);
$filterhtml .= html_writer::end_tag('form');

$resultshtml = html_writer::div(
    local_sentaldocupload_history_render_results($page, $perpage, $pageurl, $studentid, $studentq, $courseid, $doctype, $filterdocumenttypes + $documenttypes),
    'sental-results-container',
    ['id' => 'sental_history_results']
);

if ($ajax) {
    echo $resultshtml;
    die();
}

$filterjs = <<<HTML
<script>
(function() {
    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function() {
        function initStudentFilter(inputId, hiddenId, dropdownId) {
            var input = document.getElementById(inputId);
            var hidden = document.getElementById(hiddenId);
            var dropdown = document.getElementById(dropdownId);
            if (!input || !hidden || !dropdown) { return; }
            if (!dropdown.querySelector('.sental-user-no-results')) {
                var empty = document.createElement('div');
                empty.className = 'sental-user-no-results';
                empty.textContent = 'No students found';
                empty.hidden = true;
                dropdown.appendChild(empty);
            }
            function options() {
                return Array.prototype.slice.call(dropdown.querySelectorAll('.sental-filter-student-option'));
            }
            function filter(open) {
                var term = String(input.value || '').toLowerCase().trim();
                var visible = 0;
                options().forEach(function(opt) {
                    var text = String(opt.getAttribute('data-search') || opt.textContent || '').toLowerCase();
                    var show = !term || text.indexOf(term) !== -1;
                    opt.hidden = !show;
                    if (show) { visible++; }
                });
                var empty = dropdown.querySelector('.sental-user-no-results');
                if (empty) { empty.hidden = visible !== 0; }
                if (open) { dropdown.hidden = false; }
            }
            input.addEventListener('input', function() {
                hidden.value = '';
                filter(true);
            });
            input.addEventListener('focus', function() { filter(true); });
            dropdown.addEventListener('click', function(e) {
                var opt = e.target.closest ? e.target.closest('.sental-filter-student-option') : null;
                if (!opt) { return; }
                input.value = opt.getAttribute('data-label') || opt.textContent || '';
                hidden.value = opt.getAttribute('data-userid') || '';
                dropdown.hidden = true;
            });
            document.addEventListener('click', function(e) {
                if (e.target !== input && !dropdown.contains(e.target)) {
                    dropdown.hidden = true;
                }
            });
        }

        function readDocData(root) {
            var script = (root || document).querySelector('.sental-history-doc-data');
            if (!script) { return {}; }
            try {
                return JSON.parse(script.textContent || '{}') || {};
            } catch (e) {
                return {};
            }
        }

        function makeVersionOption(payload) {
            var opt = document.createElement('option');
            opt.value = payload.versionid || '';
            opt.textContent = payload.label || '';
            opt.dataset.viewurl = payload.viewurl || '';
            opt.dataset.filename = payload.filename || '-';
            opt.dataset.shortfilename = payload.shortfilename || payload.filename || '-';
            opt.dataset.issuedate = payload.issuedate || '-';
            opt.dataset.expirydate = payload.expirydate || '-';
            opt.dataset.status = payload.status || 'nodocument';
            opt.dataset.statustext = payload.statustext || 'No document';
            opt.dataset.statusclass = payload.statusclass || 'secondary';
            opt.dataset.uploadedby = payload.uploadedby || '-';
            opt.dataset.uploadedat = payload.uploadedat || '-';
            return opt;
        }

        function updateRowFromPayload(row, payload) {
            var issue = row.querySelector('.sental-version-issuedate');
            var expiry = row.querySelector('.sental-version-expirydate');
            var status = row.querySelector('.sental-version-status');
            var uploadedby = row.querySelector('.sental-version-uploadedby');
            var uploadedat = row.querySelector('.sental-version-uploadedat');
            var filecell = row.querySelector('.sental-version-filecell');
            if (issue) { issue.textContent = payload.issuedate || '-'; }
            if (expiry) { expiry.textContent = payload.expirydate || '-'; }
            if (status) {
                status.innerHTML = '';
                var badge = document.createElement('span');
                badge.className = 'badge badge-' + (payload.statusclass || 'secondary') + ' sental-cert-status sental-cert-status-' + (payload.status || 'nodocument');
                badge.textContent = payload.statustext || 'No document';
                status.appendChild(badge);
            }
            if (uploadedby) { uploadedby.textContent = payload.uploadedby || '-'; }
            if (uploadedat) { uploadedat.textContent = payload.uploadedat || '-'; }
            if (filecell) {
                var href = payload.viewurl || '#';
                var filename = payload.filename || '-';
                var shortfilename = payload.shortfilename || filename;
                filecell.innerHTML = '<a class="sental-file-link" target="_blank" href="' + href.replace(/"/g, '&quot;') + '" title="' + filename.replace(/"/g, '&quot;') + '"><span class="sental-version-filename"></span></a>';
                var name = filecell.querySelector('.sental-version-filename');
                if (name) { name.textContent = shortfilename; }
            }
        }

        function initHistoryRows(root) {
            root = root || document;
            var docdata = readDocData(root);

            root.querySelectorAll('.sental-doc-select').forEach(function(docselect) {
                if (docselect.dataset.sentalDocInit === '1') { return; }
                docselect.dataset.sentalDocInit = '1';
                docselect.addEventListener('change', function() {
                    var row = docselect.closest('tr');
                    if (!row) { return; }
                    var versionselect = row.querySelector('.sental-version-select');
                    var data = docdata[docselect.value] || null;
                    if (!versionselect || !data) { return; }
                    versionselect.innerHTML = '';
                    (data.versions || []).forEach(function(payload) {
                        var opt = makeVersionOption(payload);
                        if (String(payload.versionid) === String(data.selectedversionid)) {
                            opt.selected = true;
                        }
                        versionselect.appendChild(opt);
                    });
                    var selected = versionselect.options[versionselect.selectedIndex];
                    if (selected) {
                        updateRowFromPayload(row, {
                            viewurl: selected.dataset.viewurl,
                            filename: selected.dataset.filename,
                            shortfilename: selected.dataset.shortfilename,
                            issuedate: selected.dataset.issuedate,
                            expirydate: selected.dataset.expirydate,
                            status: selected.dataset.status,
                            statustext: selected.dataset.statustext,
                            statusclass: selected.dataset.statusclass,
                            uploadedby: selected.dataset.uploadedby,
                            uploadedat: selected.dataset.uploadedat
                        });
                    }
                });
            });

            root.querySelectorAll('.sental-version-select').forEach(function(select) {
                if (select.dataset.sentalVersionInit === '1') { return; }
                select.dataset.sentalVersionInit = '1';
                select.addEventListener('change', function() {
                    var opt = select.options[select.selectedIndex];
                    var row = select.closest('tr');
                    if (!row || !opt) { return; }
                    updateRowFromPayload(row, {
                        viewurl: opt.dataset.viewurl,
                        filename: opt.dataset.filename,
                        shortfilename: opt.dataset.shortfilename,
                        issuedate: opt.dataset.issuedate,
                        expirydate: opt.dataset.expirydate,
                        status: opt.dataset.status,
                        statustext: opt.dataset.statustext,
                        statusclass: opt.dataset.statusclass,
                        uploadedby: opt.dataset.uploadedby,
                        uploadedat: opt.dataset.uploadedat
                    });
                });
            });
        }

        function initAjaxFilter() {
            var form = document.querySelector('form[data-sental-ajax-filter="1"]');
            var container = document.getElementById('sental_history_results');
            if (!form || !container || !window.fetch) { return; }

            function buildAjaxUrl(url) {
                var parsed = new URL(url, window.location.href);
                parsed.searchParams.set('ajax', '1');
                return parsed;
            }
            function load(url, push) {
                var ajaxUrl = buildAjaxUrl(url);
                container.classList.add('sental-loading');
                fetch(ajaxUrl.toString(), {credentials: 'same-origin'})
                    .then(function(response) { return response.text(); })
                    .then(function(html) {
                        container.innerHTML = html;
                        container.classList.remove('sental-loading');
                        initHistoryRows(container);
                        var clean = new URL(ajaxUrl.toString());
                        clean.searchParams.delete('ajax');
                        if (push) { window.history.pushState({}, '', clean.toString()); }
                    })
                    .catch(function() {
                        container.classList.remove('sental-loading');
                        container.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger">Filter request failed. Please refresh and try again.</div>');
                    });
            }
            function applyFilters(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                var params = new URLSearchParams(new FormData(form));
                params.delete('page');
                var base = form.getAttribute('action') || window.location.pathname;
                load(base + '?' + params.toString(), true);
                return false;
            }
            form.addEventListener('submit', applyFilters, true);
            var applyButton = document.getElementById('sental_history_apply_filters');
            if (applyButton) {
                applyButton.addEventListener('click', applyFilters, true);
            }
            var reset = form.querySelector('.sental-filter-reset');
            if (reset) {
                reset.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    form.reset();
                    var hidden = form.querySelector('input[name="studentid"]');
                    if (hidden) { hidden.value = ''; }
                    load(reset.href, true);
                }, true);
            }
            container.addEventListener('click', function(e) {
                var link = e.target.closest ? e.target.closest('.pagination a, .paging a') : null;
                if (!link) { return; }
                e.preventDefault();
                e.stopPropagation();
                load(link.href, true);
            }, true);
            window.addEventListener('popstate', function() { load(window.location.href, false); });
        }

        initStudentFilter('id_studentq', 'id_studentid', 'sental_history_student_dropdown');
        initHistoryRows(document);
        initAjaxFilter();
    });
})();
</script>
HTML;

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('versionhistorydesc', 'local_sentaldocupload'), ['class' => 'alert alert-info']);
echo html_writer::div(
    html_writer::link(new moodle_url('/local/sentaldocupload/index.php'), get_string('opendocumentupload', 'local_sentaldocupload'), ['class' => 'btn btn-primary mr-2']) . ' ' .
    html_writer::link(new moodle_url('/local/sentaldocupload/audit.php'), get_string('audittrail', 'local_sentaldocupload'), ['class' => 'btn btn-secondary']),
    'mb-3'
);
echo $filterhtml;
echo $resultshtml;
echo $filterjs;
echo $OUTPUT->footer();
