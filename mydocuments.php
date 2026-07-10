<?php
// Student Certifications page.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$context = context_system::instance();
$userid = (int)$USER->id;
$requestedcourseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/sentaldocupload/mydocuments.php', $requestedcourseid > 0 ? ['courseid' => $requestedcourseid] : []));
$PAGE->set_title(get_string('certifications', 'local_sentaldocupload'));
$PAGE->set_heading(get_string('certifications', 'local_sentaldocupload'));
$PAGE->requires->css(new moodle_url('/local/sentaldocupload/styles.css'));

$courseimageurl = static function(int $courseid) use ($PAGE) : string {
    global $OUTPUT;

    $normaliseurl = static function($image): string {
        if ($image instanceof moodle_url) {
            return $image->out(false);
        }
        if (is_string($image) && trim($image) !== '') {
            return trim($image);
        }
        return '';
    };

    $course = null;
    try {
        $course = get_course($courseid);
    } catch (Throwable $e) {
        $course = null;
    }

    // 1) First use the real uploaded course overview image, if the course has one.
    try {
        if ($course && class_exists('\\core_course\\external\\course_summary_exporter')) {
            $image = \core_course\external\course_summary_exporter::get_course_image($course);
            $image = $normaliseurl($image);
            if ($image !== '') {
                return $image;
            }
        }
    } catch (Throwable $e) {
        // Continue to direct overviewfiles lookup.
    }

    // 2) Direct Moodle course overviewfiles fallback.
    $context = context_course::instance($courseid, IGNORE_MISSING);
    if ($context) {
        try {
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'sortorder, filepath, filename', false);
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                if (method_exists($file, 'is_valid_image') && !$file->is_valid_image()) {
                    continue;
                }
                $url = moodle_url::make_pluginfile_url(
                    $context->id,
                    'course',
                    'overviewfiles',
                    0,
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                );
                return $url->out(false);
            }
        } catch (Throwable $e) {
            // Continue to Moodle generated image.
        }
    }

    // 3) Use Moodle/theme generated course card image.
    // This should match the default image Moodle shows on course cards when no overview image exists.
    try {
        $renderers = [];
        if (!empty($OUTPUT)) {
            $renderers[] = $OUTPUT;
        }
        if (!empty($PAGE)) {
            $renderers[] = $PAGE->get_renderer('core');
        }

        foreach ($renderers as $renderer) {
            if ($renderer && method_exists($renderer, 'get_generated_image_for_id')) {
                $generated = $renderer->get_generated_image_for_id($courseid);
                $generated = $normaliseurl($generated);
                if ($generated !== '') {
                    return $generated;
                }
            }
        }
    } catch (Throwable $e) {
        // Continue to plugin visual fallback.
    }

    // 4) Last fallback is CSS only, styled to match Moodle's default course-card image.
    return '';
};

// My Certifications is the student's private document area.
// It shows ALL Type 1 and Type 2 documents linked to the logged-in learner.
// The Show in Public Profile checkbox is NOT used here; it is only used for the public Moodle user profile.
$docsql = "SELECT v.id AS id,
                  d.id AS documentid,
                  d.courseid,
                  c.fullname AS coursefullname,
                  c.shortname AS courseshortname,
                  d.documenttype,
                  d.customlabel,
                  d.issuedate AS docissuedate,
                  d.expirydate AS docexpirydate,
                  d.currentversion,
                  du.userid,
                  du.showinpublicprofile,
                  du.publicprofileoverride,
                  v.id AS versionid,
                  v.versionno,
                  v.filename,
                  v.customlabel AS versionlabel,
                  v.issuedate,
                  v.expirydate,
                  v.showinpublicprofile AS version_showinpublicprofile,
                  v.timecreated,
                  uploader.firstname AS uploaderfirstname,
                  uploader.lastname AS uploaderlastname
             FROM {sental_modeb_doc_user} du
             JOIN {sental_modeb_doc} d ON d.id = du.documentid
             JOIN {sental_modeb_doc_version} v ON v.documentid = d.id
             JOIN {course} c ON c.id = d.courseid
        LEFT JOIN {user} uploader ON uploader.id = v.uploadedby
            WHERE du.userid = :userid
              AND d.documenttype IN ('type1', 'type2')
              AND c.id <> :siteid
         ORDER BY c.fullname ASC, d.documenttype ASC, d.id ASC, v.versionno DESC";
$versions = $DB->get_records_sql($docsql, ['userid' => $userid, 'siteid' => SITEID]);

$courses = [];
$documentsbycourse = [];
$doccountbycourse = [];
$statussourcebycourse = [];
$latestexpirybycourse = [];
$primaryversionbycourse = [];

foreach ($versions as $row) {
    $courseid = (int)$row->courseid;
    $documentid = (int)$row->documentid;
    $type = (string)$row->documenttype;

    if (!isset($courses[$courseid])) {
        $courses[$courseid] = (object)[
            'id' => $courseid,
            'fullname' => $row->coursefullname,
            'shortname' => $row->courseshortname,
        ];
        $documentsbycourse[$courseid] = [];
        $doccountbycourse[$courseid] = [];
    }
    if (!isset($documentsbycourse[$courseid][$type])) {
        $documentsbycourse[$courseid][$type] = [];
    }
    if (!isset($documentsbycourse[$courseid][$type][$documentid])) {
        $documentsbycourse[$courseid][$type][$documentid] = [
            'documentid' => $documentid,
            'documenttype' => $type,
            'label' => (string)($row->customlabel ?: ''),
            'versions' => [],
        ];
    }

    $documentsbycourse[$courseid][$type][$documentid]['versions'][] = $row;
    $doccountbycourse[$courseid][$documentid] = true;
}

// Course-card status is based on the latest Type 1 course-completion version when available.
// If the learner only has Type 2 supplementary documents for the course, use the latest document status so the card still has useful information.
foreach ($documentsbycourse as $courseid => $types) {
    $candidates = [];
    if (!empty($types['type1'])) {
        foreach ($types['type1'] as $doc) {
            if (!empty($doc['versions'][0])) {
                $candidates[] = $doc['versions'][0];
            }
        }
    } else {
        foreach ($types as $docs) {
            foreach ($docs as $doc) {
                if (!empty($doc['versions'][0])) {
                    $candidates[] = $doc['versions'][0];
                }
            }
        }
    }

    $best = null;
    foreach ($candidates as $candidate) {
        if ($best === null || (int)$candidate->timecreated > (int)$best->timecreated) {
            $best = $candidate;
        }
    }

    if ($best) {
        $latestexpirybycourse[$courseid] = empty($best->expirydate) ? null : (int)$best->expirydate;
        $primaryversionbycourse[$courseid] = (int)$best->versionid;
        $statussourcebycourse[$courseid] = local_sentaldocupload_get_status($latestexpirybycourse[$courseid], true);
    } else {
        $latestexpirybycourse[$courseid] = null;
        $statussourcebycourse[$courseid] = 'nodocument';
    }
}

$formatdate = static function($timestamp) {
    return empty($timestamp) ? get_string('noexpiry', 'local_sentaldocupload') : userdate((int)$timestamp, get_string('strftimedate', 'langconfig'));
};

$coursepayload = [];
foreach ($courses as $course) {
    $courseid = (int)$course->id;
    $typedata = [];

    foreach (($documentsbycourse[$courseid] ?? []) as $type => $docs) {
        $typedata[$type] = [];
        foreach ($docs as $doc) {
            $versionspayload = [];
            foreach ($doc['versions'] as $row) {
                $status = local_sentaldocupload_get_status(empty($row->expirydate) ? null : (int)$row->expirydate, true);
                $versionspayload[] = [
                    'versionid' => (int)$row->versionid,
                    'versionno' => (int)$row->versionno,
                    'filename' => (string)($row->filename ?: get_string('file', 'local_sentaldocupload')),
                    'label' => (string)($row->versionlabel ?: $row->customlabel ?: ''),
                    'issuedate' => $formatdate($row->issuedate),
                    'expirydate' => $formatdate($row->expirydate),
                    'status' => $status,
                    'statushtml' => local_sentaldocupload_status_badge($status),
                    'uploadedat' => empty($row->timecreated) ? '-' : userdate((int)$row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                    'uploadedby' => trim((string)$row->uploaderfirstname . ' ' . (string)$row->uploaderlastname),
                    'downloadurl' => (new moodle_url('/local/sentaldocupload/download.php', ['versionid' => (int)$row->versionid]))->out(false),
                    'viewurl' => (new moodle_url('/local/sentaldocupload/viewer.php', ['versionid' => (int)$row->versionid]))->out(false),
                ];
            }
            if (!empty($versionspayload)) {
                $typedata[$type][] = [
                    'documentid' => (int)$doc['documentid'],
                    'documenttype' => $type,
                    'label' => (string)$doc['label'],
                    'versions' => $versionspayload,
                ];
            }
        }
    }

    $status = $statussourcebycourse[$courseid] ?? 'nodocument';
    $coursepayload[$courseid] = [
        'courseid' => $courseid,
        'fullname' => format_string($course->fullname),
        'shortname' => format_string($course->shortname),
        'status' => $status,
        'statushtml' => local_sentaldocupload_status_badge($status),
        'latestexpiry' => array_key_exists($courseid, $latestexpirybycourse) ? $formatdate($latestexpirybycourse[$courseid]) : get_string('statusnodocument', 'local_sentaldocupload'),
        'documentcount' => count($doccountbycourse[$courseid] ?? []),
        'courseimage' => $courseimageurl($courseid),
        'types' => $typedata,
    ];
}

$selectedcourseid = ($requestedcourseid > 0 && isset($coursepayload[$requestedcourseid])) ? $requestedcourseid : 0;
$payloadjson = json_encode($coursepayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$langjson = json_encode([
    'all' => get_string('all_documents', 'local_sentaldocupload'),
    'type1' => get_string('doctype_type1_short', 'local_sentaldocupload'),
    'type2' => get_string('doctype_type2_short', 'local_sentaldocupload'),
    'nodocs' => get_string('nodocumentsfortype', 'local_sentaldocupload'),
    'view' => get_string('viewfile', 'local_sentaldocupload'),
    'version' => get_string('versionno', 'local_sentaldocupload'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$PAGE->requires->js_init_code(<<<JS
(function() {
    var courses = {$payloadjson};
    var preferredCourseId = {$selectedcourseid};
    var lang = {$langjson};
    var selectedCourseId = null;

    function qs(selector) { return document.querySelector(selector); }
    function qsa(selector) { return Array.prototype.slice.call(document.querySelectorAll(selector)); }

    function typeLabel(type) {
        if (type === 'type1') { return lang.type1 || type; }
        if (type === 'type2') { return lang.type2 || type; }
        if (type === 'all') { return lang.all || type; }
        return type;
    }

    function pickVersion(documentRow, versionid) {
        var versions = documentRow.versions || [];
        for (var i = 0; i < versions.length; i++) {
            if (String(versions[i].versionid) === String(versionid)) {
                return versions[i];
            }
        }
        return versions[0] || null;
    }

    function updateTableRow(tr, documentRow, versionid) {
        var selected = pickVersion(documentRow, versionid);
        if (!selected) { return; }

        var filelink = tr.querySelector('.sental-student-file-link');
        var versionselect = tr.querySelector('.sental-student-version-select');
        var issuetd = tr.querySelector('[data-field="issue"]');
        var expirytd = tr.querySelector('[data-field="expiry"]');
        var statustd = tr.querySelector('[data-field="status"]');
        var view = tr.querySelector('.sental-student-view-link');

        if (filelink) {
            filelink.href = selected.viewurl;
            filelink.textContent = selected.filename;
            filelink.title = selected.filename;
        }
        if (versionselect) {
            versionselect.value = String(selected.versionid);
        }
        if (issuetd) { issuetd.textContent = selected.issuedate; }
        if (expirytd) { expirytd.textContent = selected.expirydate; }
        if (statustd) { statustd.innerHTML = selected.statushtml; }
        if (view) { view.href = selected.viewurl; }
    }

    function renderRows(courseid, type) {
        var tbody = qs('#sental-student-versions tbody');
        var empty = qs('#sental-student-empty');
        var table = qs('#sental-student-versions');
        if (!tbody || !table || !empty) { return; }
        tbody.innerHTML = '';
        var rows = [];
        var alltypes = ((courses[courseid] || {}).types || {});
        if (type === 'all') {
            ['type1', 'type2'].forEach(function(t) {
                (alltypes[t] || []).forEach(function(row) {
                    row.__doctype = t;
                    rows.push(row);
                });
            });
        } else {
            rows = (alltypes[type] || []).map(function(row) { row.__doctype = type; return row; });
        }
        if (!rows.length) {
            empty.style.display = 'block';
            table.style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        table.style.display = 'table';

        rows.forEach(function(documentRow) {
            var selected = (documentRow.versions || [])[0];
            if (!selected) { return; }

            var tr = document.createElement('tr');
            tr.setAttribute('data-documentid', documentRow.documentid);

            var filetd = document.createElement('td');
            var link = document.createElement('a');
            link.href = selected.viewurl;
            link.className = 'sental-student-file-link';
            link.textContent = selected.filename;
            link.title = selected.filename;
            filetd.appendChild(link);
            if (documentRow.label && (documentRow.__doctype || type) === 'type2') {
                var label = document.createElement('div');
                label.className = 'sental-student-file-label';
                label.textContent = documentRow.label;
                filetd.appendChild(label);
            }
            tr.appendChild(filetd);

            var typetd = document.createElement('td');
            typetd.textContent = typeLabel(documentRow.__doctype || type);
            tr.appendChild(typetd);

            var versiontd = document.createElement('td');
            var versionselect = document.createElement('select');
            versionselect.className = 'custom-select custom-select-sm sental-student-version-select';
            versionselect.setAttribute('aria-label', lang.version || '');
            (documentRow.versions || []).forEach(function(version) {
                var opt = document.createElement('option');
                opt.value = String(version.versionid);
                opt.textContent = 'v' + version.versionno;
                versionselect.appendChild(opt);
            });
            if ((documentRow.versions || []).length <= 1) {
                versionselect.disabled = true;
            }
            versiontd.appendChild(versionselect);
            tr.appendChild(versiontd);

            var issuetd = document.createElement('td');
            issuetd.setAttribute('data-field', 'issue');
            tr.appendChild(issuetd);

            var expirytd = document.createElement('td');
            expirytd.setAttribute('data-field', 'expiry');
            tr.appendChild(expirytd);

            var statustd = document.createElement('td');
            statustd.setAttribute('data-field', 'status');
            tr.appendChild(statustd);

            var actiontd = document.createElement('td');
            var dl = document.createElement('a');
            dl.href = selected.viewurl;
            dl.className = 'btn btn-sm btn-outline-success sental-student-view-link';
            dl.textContent = lang.view || '';
            actiontd.appendChild(dl);
            tr.appendChild(actiontd);

            versionselect.addEventListener('change', function() {
                updateTableRow(tr, documentRow, versionselect.value);
            });
            updateTableRow(tr, documentRow, selected.versionid);
            tbody.appendChild(tr);
        });
    }

    function renderTypes(courseid) {
        var select = qs('#sental-student-doctype');
        var section = qs('#sental-student-detail');
        var course = courses[courseid];
        selectedCourseId = courseid;
        if (!select || !section || !course) { return; }
        qsa('.sental-student-course-card').forEach(function(card) {
            card.classList.toggle('is-active', card.getAttribute('data-courseid') === String(courseid));
        });
        qs('#sental-student-selected-course').textContent = course.fullname;
        select.innerHTML = '';
        var types = Object.keys(course.types || {});
        var order = {type1: 1, type2: 2};
        types.sort(function(a, b) { return (order[a] || 99) - (order[b] || 99); });
        if (!types.length) {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = lang.nodocs || '';
            select.appendChild(opt);
            select.disabled = true;
            renderRows(courseid, '');
        } else {
            select.disabled = false;
            var allopt = document.createElement('option');
            allopt.value = 'all';
            allopt.textContent = typeLabel('all');
            select.appendChild(allopt);
            types.forEach(function(type) {
                var opt = document.createElement('option');
                opt.value = type;
                opt.textContent = typeLabel(type);
                select.appendChild(opt);
            });
            select.value = 'all';
            renderRows(courseid, select.value);
        }
        section.style.display = 'block';
    }

    document.addEventListener('click', function(e) {
        var card = e.target.closest('.sental-student-course-card');
        if (card) {
            e.preventDefault();
            var courseid = card.getAttribute('data-courseid');
            renderTypes(courseid);

            // Course card must not open the document viewer directly.
            // It should open/select this course's certificate/document section on the same page.
            var detail = qs('#sental-student-detail');
            if (detail && detail.scrollIntoView) {
                detail.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }
    });
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'sental-student-doctype' && selectedCourseId) {
            renderRows(selectedCourseId, e.target.value);
        }
    });

    if (preferredCourseId && courses[preferredCourseId]) {
        renderTypes(preferredCourseId);
    } else {
        var first = qs('.sental-student-course-card');
        if (first) {
            renderTypes(first.getAttribute('data-courseid'));
        }
    }
})();
JS, true);

echo $OUTPUT->header();

echo html_writer::start_div('sental-student-docs-page');
echo html_writer::tag('p', get_string('certifications_desc', 'local_sentaldocupload'), ['class' => 'sental-page-subtitle']);

if (empty($courses)) {
    echo $OUTPUT->notification(get_string('nocertificationcourses', 'local_sentaldocupload'), core\output\notification::NOTIFY_INFO);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('sental-student-course-grid');
foreach ($courses as $course) {
    $courseid = (int)$course->id;
    $status = $statussourcebycourse[$courseid] ?? 'nodocument';
    $classes = 'sental-student-course-card status-' . $status;
    echo html_writer::start_tag('button', [
        'type' => 'button',
        'class' => $classes,
        'data-courseid' => $courseid,
    ]);
    $imageurl = (string)($coursepayload[$courseid]['courseimage'] ?? '');
    $cssimageurl = str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "", ""], $imageurl);
    $imageclass = 'sental-student-card-image ' . ($imageurl !== '' ? 'has-moodle-course-image' : 'is-moodle-default-fallback');
    $imagestyle = $imageurl !== ''
        ? '--sental-course-image:url("' . s($cssimageurl) . '");'
        : '--sental-course-image:none;';
    echo html_writer::div('', $imageclass, [
        'style' => $imagestyle,
        'aria-label' => format_string($course->fullname),
    ]);
    echo html_writer::div(local_sentaldocupload_status_badge($status), 'sental-student-card-top');
    echo html_writer::tag('strong', format_string($course->fullname), ['class' => 'sental-student-course-title']);
    echo html_writer::div(get_string('latestexpiry', 'local_sentaldocupload') . ': ' . s($coursepayload[$courseid]['latestexpiry']), 'sental-student-card-meta');
    echo html_writer::div(get_string('documentscount', 'local_sentaldocupload', (object)['count' => (int)$coursepayload[$courseid]['documentcount']]), 'sental-student-card-meta');
    echo html_writer::end_tag('button');
}
echo html_writer::end_div();

echo html_writer::start_div('sental-student-detail', ['id' => 'sental-student-detail', 'style' => 'display:none']);
echo html_writer::tag('div', '', ['id' => 'sental-student-selected-course', 'class' => 'sental-student-selected-course-name']);
echo html_writer::start_div('sental-student-type-select-wrap');
echo html_writer::label(get_string('documenttype', 'local_sentaldocupload'), 'sental-student-doctype', false, ['class' => 'sental-student-select-label']);
echo html_writer::select([], 'sental-student-doctype', '', false, ['id' => 'sental-student-doctype', 'class' => 'custom-select sental-student-type-select']);
echo html_writer::end_div();

echo html_writer::start_div('sental-student-table-wrap');
echo html_writer::start_tag('table', ['class' => 'generaltable sental-student-versions-table', 'id' => 'sental-student-versions']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
$headers = [get_string('file', 'local_sentaldocupload'), get_string('documenttype', 'local_sentaldocupload'), get_string('versionno', 'local_sentaldocupload'), get_string('issuedate', 'local_sentaldocupload'), get_string('expirydate', 'local_sentaldocupload'), get_string('certificationstatus', 'local_sentaldocupload'), get_string('action', 'local_sentaldocupload')];
foreach ($headers as $header) {
    echo html_writer::tag('th', $header);
}
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::tag('tbody', '');
echo html_writer::end_tag('table');
echo html_writer::div(get_string('nodocumentsfortype', 'local_sentaldocupload'), 'alert alert-info', ['id' => 'sental-student-empty', 'style' => 'display:none']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
