<?php
// Immutable audit trail for manual document actions.

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
$actiontype = optional_param('actiontype', '', PARAM_ALPHANUMEXT);
$actorid = optional_param('actorid', 0, PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_BOOL);
$perpage = 25;

$urlparams = [];
foreach (['studentid' => $studentid, 'studentq' => $studentq, 'courseid' => $courseid, 'doctype' => $doctype, 'actiontype' => $actiontype, 'actorid' => $actorid] as $key => $value) {
    if ($value !== '' && (string)$value !== '0') {
        $urlparams[$key] = $value;
    }
}
$pageurl = new moodle_url('/local/sentaldocupload/audit.php', $urlparams);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('audittrail', 'local_sentaldocupload'));
$PAGE->set_heading(get_string('audittrail', 'local_sentaldocupload'));
$PAGE->requires->css(new moodle_url('/local/sentaldocupload/styles.css'));

$documenttypes = [
    'type1' => get_string('doctype_type1_short', 'local_sentaldocupload'),
    'type2' => get_string('doctype_type2_short', 'local_sentaldocupload'),
];

$actions = [
    'upload' => local_sentaldocupload_get_audit_action_label('upload'),
    'replace' => local_sentaldocupload_get_audit_action_label('replace'),
    'view' => local_sentaldocupload_get_audit_action_label('view'),
    'download' => local_sentaldocupload_get_audit_action_label('download'),
    'course_completed' => local_sentaldocupload_get_audit_action_label('course_completed'),
    'public_view' => local_sentaldocupload_get_audit_action_label('public_view'),
];

function local_sentaldocupload_audit_filter_select($name, array $options, $selected, $alllabel) {
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

function local_sentaldocupload_audit_build_where(int $studentid, string $studentq, int $courseid, string $doctype, string $actiontype, int $actorid, array $documenttypes, array $actions): array {
    global $DB;
    $where = [];
    $params = [];

    if ($studentid > 0) {
        $where[] = 'a.userid = :filterstudentid';
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
    if ($actiontype !== '' && array_key_exists($actiontype, $actions)) {
        $where[] = 'a.actiontype = :actiontype';
        $params['actiontype'] = $actiontype;
    }
    if ($actorid > 0) {
        $where[] = 'a.actorid = :actorid';
        $params['actorid'] = $actorid;
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function local_sentaldocupload_audit_render_results(int $page, int $perpage, moodle_url $pageurl, int $studentid, string $studentq, int $courseid, string $doctype, string $actiontype, int $actorid, array $documenttypes, array $actions): string {
    global $DB, $OUTPUT;

    [$whereclause, $params] = local_sentaldocupload_audit_build_where($studentid, $studentq, $courseid, $doctype, $actiontype, $actorid, $documenttypes, $actions);

    $fromsql = "
          FROM {sental_modeb_audit} a
          JOIN {sental_modeb_doc} d ON d.id = a.documentid
     LEFT JOIN {sental_modeb_doc_version} v ON v.id = a.versionid
     LEFT JOIN {course} c ON c.id = d.courseid
     LEFT JOIN {user} actor ON actor.id = a.actorid
     LEFT JOIN {user} learner ON learner.id = a.userid
    ";

    $total = $DB->count_records_sql('SELECT COUNT(1) ' . $fromsql . $whereclause, $params);
    $sql = "SELECT a.id,
                   a.versionid,
                   a.userid,
                   a.actorid,
                   a.ipaddress,
                   a.actiontype,
                   a.timecreated,
                   d.courseid,
                   d.documenttype,
                   d.customlabel AS documentlabel,
                   v.versionno,
                   v.filename,
                   c.fullname AS coursename,
                   actor.firstname AS actorfirstname,
                   actor.lastname AS actorlastname,
                   actor.email AS actoremail,
                   learner.firstname AS learnerfirstname,
                   learner.lastname AS learnerlastname,
                   learner.email AS learneremail
" . $fromsql . $whereclause . "
      ORDER BY a.timecreated DESC, a.id DESC";
    $logs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

    $html = html_writer::start_div('sental-ajax-results-inner');
    $html .= html_writer::tag('p', get_string('pagination_summary', 'local_sentaldocupload', (object)[
        'total' => $total,
        'perpage' => $perpage,
    ]), ['class' => 'text-muted']);

    if (!$logs) {
        $html .= $OUTPUT->notification(get_string('noauditlogs', 'local_sentaldocupload'), core\output\notification::NOTIFY_INFO);
        $html .= html_writer::end_div();
        return $html;
    }

    $table = new html_table();
    $table->head = [
        get_string('audit_timestamp', 'local_sentaldocupload'),
        get_string('audit_actor', 'local_sentaldocupload'),
        get_string('audit_ipaddress', 'local_sentaldocupload'),
        get_string('audit_document', 'local_sentaldocupload'),
        get_string('audit_version', 'local_sentaldocupload'),
        get_string('audit_actiontype', 'local_sentaldocupload'),
        get_string('audit_learner', 'local_sentaldocupload'),
    ];
    $table->attributes['class'] = 'generaltable sental-audit-trail sental-audit-v055';

    foreach ($logs as $log) {
        $actor = fullname((object)[
            'firstname' => $log->actorfirstname ?? '',
            'lastname' => $log->actorlastname ?? '',
        ]);
        if (!empty($log->actoremail)) {
            $actor .= html_writer::empty_tag('br') . html_writer::span(s($log->actoremail), 'text-muted small');
        }

        $learner = '-';
        if (!empty($log->userid)) {
            $learnername = fullname((object)[
                'firstname' => $log->learnerfirstname ?? '',
                'lastname' => $log->learnerlastname ?? '',
            ]);
            $learnerurl = new moodle_url('/user/profile.php', ['id' => (int)$log->userid]);
            $learner = html_writer::link($learnerurl, s($learnername), ['class' => 'sental-table-mainlink']);
            if (!empty($log->learneremail)) {
                $learner .= html_writer::empty_tag('br') . html_writer::span(s($log->learneremail), 'text-muted small');
            }
        }

        $doctype = $documenttypes[$log->documenttype] ?? s($log->documenttype);
        $courselabel = !empty($log->coursename) ? format_string($log->coursename) : '-';
        if (!empty($log->courseid)) {
            $courseurl = new moodle_url('/course/view.php', ['id' => (int)$log->courseid]);
            $courselabel = html_writer::link($courseurl, $courselabel, ['class' => 'sental-table-mainlink']);
        }
        $docparts = [
            $courselabel,
            $doctype,
        ];
        // Custom labels are user-entered data. Show them only for Type 2 supplementary
        // documents. Type 1 labels from older uploads may contain stored English text
        // such as "Course completion document", so we do not show that stored label here.
        if ($log->documenttype === 'type2' && !empty($log->documentlabel)) {
            $docparts[] = format_string($log->documentlabel);
        }
        $document = implode(html_writer::empty_tag('br'), $docparts);

        $version = '-';
        if (!empty($log->versionid)) {
            $auditfilename = (string)($log->filename ?: '-');
            if (core_text::strlen($auditfilename) > 22) {
                $ext = '';
                if (preg_match('/\.[^.]+$/', (string)$log->filename, $m)) {
                    $ext = $m[0];
                }
                $auditfilename = core_text::substr($auditfilename, 0, max(8, 18 - core_text::strlen($ext))) . '...' . $ext;
            }
            $version = html_writer::span('v' . (int)$log->versionno, 'sental-audit-version-no') . html_writer::empty_tag('br') . html_writer::span(s($auditfilename), 'sental-audit-filename', ['title' => s((string)($log->filename ?: '-'))]);
        }

        $table->data[] = [
            userdate((int)$log->timecreated),
            $actor ?: '-',
            s($log->ipaddress ?: '-'),
            $document,
            $version,
            local_sentaldocupload_get_audit_action_label((string)$log->actiontype),
            $learner,
        ];
    }

    $html .= html_writer::div(html_writer::table($table), 'sental-audit-table-wrap');
    $html .= $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
    $html .= html_writer::end_div();
    return $html;
}

$courses = $DB->get_records_sql_menu("SELECT DISTINCT c.id, c.fullname
                                        FROM {course} c
                                        JOIN {sental_modeb_doc} d ON d.courseid = c.id
                                    ORDER BY c.fullname ASC");
$courseoptions = [];
foreach ($courses as $id => $name) {
    $courseoptions[(string)$id] = format_string($name);
}

$learners = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                                    FROM {user} u
                                    JOIN {sental_modeb_audit} a ON a.userid = u.id
                                   WHERE a.userid IS NOT NULL AND a.userid > 0
                                ORDER BY u.lastname ASC, u.firstname ASC, u.email ASC");
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

$actors = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                                  FROM {user} u
                                  JOIN {sental_modeb_audit} a ON a.actorid = u.id
                                 WHERE a.actorid IS NOT NULL AND a.actorid > 0
                              ORDER BY u.lastname ASC, u.firstname ASC, u.email ASC");
$actoroptions = [];
foreach ($actors as $actor) {
    $actoroptions[(string)$actor->id] = fullname($actor) . (!empty($actor->email) ? ' (' . $actor->email . ')' : '');
}

$filterhtml = html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/sentaldocupload/audit.php'))->out(false), 'class' => 'sental-filter-form mb-3', 'data-sental-ajax-filter' => '1', 'onsubmit' => 'return false;']);
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
        'aria-controls' => 'sental_audit_student_dropdown',
    ]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'studentid', 'id' => 'id_studentid', 'value' => $studentid > 0 ? $studentid : '']) .
    html_writer::start_div('sental-user-dropdown sental-filter-student-dropdown', ['id' => 'sental_audit_student_dropdown', 'hidden' => 'hidden']) .
    $studentoptionshtml .
    html_writer::end_div() .
    html_writer::end_div(),
    'col-md-3 mb-2'
);
$filterhtml .= html_writer::div(
    html_writer::label(get_string('course', 'local_sentaldocupload'), 'id_courseid') .
    local_sentaldocupload_audit_filter_select('courseid', $courseoptions, $courseid, get_string('filter_all_courses', 'local_sentaldocupload')),
    'col-md-3 mb-2'
);
$filterhtml .= html_writer::div(
    html_writer::label(get_string('documenttype', 'local_sentaldocupload'), 'id_doctype') .
    local_sentaldocupload_audit_filter_select('doctype', $documenttypes, $doctype, get_string('filter_all_document_types', 'local_sentaldocupload')),
    'col-md-3 mb-2'
);
$filterhtml .= html_writer::div(
    html_writer::label(get_string('audit_actiontype', 'local_sentaldocupload'), 'id_actiontype') .
    local_sentaldocupload_audit_filter_select('actiontype', $actions, $actiontype, get_string('filter_all_actions', 'local_sentaldocupload')),
    'col-md-3 mb-2'
);
$filterhtml .= html_writer::div(
    html_writer::label(get_string('audit_actor', 'local_sentaldocupload'), 'id_actorid') .
    local_sentaldocupload_audit_filter_select('actorid', $actoroptions, $actorid, get_string('filter_all_actors', 'local_sentaldocupload')),
    'col-md-3 mb-2'
);
$filterhtml .= html_writer::end_div();
$filterhtml .= html_writer::div(
    html_writer::tag('button', get_string('filter_apply', 'local_sentaldocupload'), ['type' => 'button', 'id' => 'sental_audit_apply_filters', 'class' => 'btn btn-primary mr-2']) .
    html_writer::link(new moodle_url('/local/sentaldocupload/audit.php'), get_string('filter_reset', 'local_sentaldocupload'), ['class' => 'btn btn-secondary sental-filter-reset']),
    'mt-2'
);
$filterhtml .= html_writer::end_tag('form');

$resultshtml = html_writer::div(
    local_sentaldocupload_audit_render_results($page, $perpage, $pageurl, $studentid, $studentq, $courseid, $doctype, $actiontype, $actorid, $documenttypes, $actions),
    'sental-results-container',
    ['id' => 'sental_audit_results']
);

if ($ajax) {
    echo $resultshtml;
    die();
}

$nostudentsfoundjs = json_encode(get_string('nostudentsfound', 'local_sentaldocupload'));
$filterfailedjs = json_encode(get_string('filter_request_failed', 'local_sentaldocupload'));

$filterjs = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    function initStudentFilter(inputId, hiddenId, dropdownId) {
        var input = document.getElementById(inputId);
        var hidden = document.getElementById(hiddenId);
        var dropdown = document.getElementById(dropdownId);
        if (!input || !hidden || !dropdown) { return; }
        if (!dropdown.querySelector('.sental-user-no-results')) {
            var empty = document.createElement('div');
            empty.className = 'sental-user-no-results';
            empty.textContent = {$nostudentsfoundjs};
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
        input.addEventListener('input', function() { hidden.value = ''; filter(true); });
        input.addEventListener('focus', function() { filter(true); });
        dropdown.addEventListener('click', function(e) {
            var opt = e.target.closest ? e.target.closest('.sental-filter-student-option') : null;
            if (!opt) { return; }
            input.value = opt.getAttribute('data-label') || opt.textContent || '';
            hidden.value = opt.getAttribute('data-userid') || '';
            dropdown.hidden = true;
        });
        document.addEventListener('click', function(e) {
            if (e.target !== input && !dropdown.contains(e.target)) { dropdown.hidden = true; }
        });
    }
    function initAjaxFilter() {
        var form = document.querySelector('form[data-sental-ajax-filter="1"]');
        var container = document.getElementById('sental_audit_results');
        if (!form || !container || !window.fetch) { return; }
        function buildUrl(url) {
            var parsed = new URL(url, window.location.href);
            parsed.searchParams.set('ajax', '1');
            return parsed;
        }
        function load(url, push) {
            var ajaxUrl = buildUrl(url);
            container.classList.add('sental-loading');
            fetch(ajaxUrl.toString(), {credentials: 'same-origin'})
                .then(function(response) { return response.text(); })
                .then(function(html) {
                    container.innerHTML = html;
                    container.classList.remove('sental-loading');
                    var clean = new URL(ajaxUrl.toString());
                    clean.searchParams.delete('ajax');
                    if (push) { window.history.pushState({}, '', clean.toString()); }
                })
                .catch(function() { container.classList.remove('sental-loading'); container.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger">' + {$filterfailedjs} + '</div>'); });
        }
        function applyAuditFilters(e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            var params = new URLSearchParams(new FormData(form));
            params.delete('page');
            var url = form.getAttribute('action') || window.location.pathname;
            load(url + '?' + params.toString(), true);
            return false;
        }
        form.addEventListener('submit', applyAuditFilters, true);
        var applyButton = document.getElementById('sental_audit_apply_filters');
        if (applyButton) { applyButton.addEventListener('click', applyAuditFilters, true); }
        var reset = form.querySelector('.sental-filter-reset');
        if (reset) {
            reset.addEventListener('click', function(e) {
                e.preventDefault();
                form.reset();
                var hidden = form.querySelector('input[name="studentid"]');
                if (hidden) { hidden.value = ''; }
                load(reset.href, true);
            });
        }
        container.addEventListener('click', function(e) {
            var link = e.target.closest ? e.target.closest('.pagination a, .paging a') : null;
            if (!link) { return; }
            e.preventDefault();
            load(link.href, true);
        });
        window.addEventListener('popstate', function() { load(window.location.href, false); });
    }
    initStudentFilter('id_studentq', 'id_studentid', 'sental_audit_student_dropdown');
    initAjaxFilter();
});
</script>
HTML;

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/sentaldocupload/index.php'), get_string('opendocumentupload', 'local_sentaldocupload'), ['class' => 'btn btn-primary mr-2']) . ' ' .
    html_writer::link(new moodle_url('/local/sentaldocupload/history.php'), get_string('historylink', 'local_sentaldocupload'), ['class' => 'btn btn-secondary']),
    'mb-3'
);
echo $filterhtml;
echo $resultshtml;
echo $filterjs;
echo $OUTPUT->footer();
