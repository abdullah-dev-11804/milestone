<?php
// Employee course record page for SENTAL Documents.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/completionlib.php');

$courseid = required_param('courseid', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

require_login();

$context = context_system::instance();
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($courseid, IGNORE_MISSING);

if ($userid <= 0) {
    $userid = (int)$USER->id;
}

$learner = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$isownrecord = (int)$USER->id === $userid;
$canviewdocuments = has_capability('local/sentaldocupload:viewdocuments', $context)
    || has_capability('local/sentaldocupload:manage', $context);
$isenrolled = $coursecontext && is_enrolled($coursecontext, $learner, '', true);

if (!$isownrecord && !$canviewdocuments) {
    throw new required_capability_exception($context, 'local/sentaldocupload:viewdocuments', 'nopermissions', 'error');
}

/**
 * Resolve a custom user profile value by possible shortnames.
 *
 * @param int $userid
 * @param array $shortnames
 * @return string
 */
function local_sentaldocupload_course_record_user_info(int $userid, array $shortnames): string {
    global $DB;

    $wanted = [];
    foreach ($shortnames as $shortname) {
        $normalised = preg_replace('/[^a-z0-9]+/', '', core_text::strtolower((string)$shortname));
        if ($normalised !== '') {
            $wanted[$normalised] = true;
        }
    }

    $records = $DB->get_records_sql(
        "SELECT d.id, f.shortname, d.data
           FROM {user_info_data} d
           JOIN {user_info_field} f ON f.id = d.fieldid
          WHERE d.userid = :userid",
        ['userid' => $userid]
    );

    foreach ($records as $record) {
        $shortname = preg_replace('/[^a-z0-9]+/', '', core_text::strtolower((string)$record->shortname));
        $value = trim((string)$record->data);
        if ($value !== '' && isset($wanted[$shortname])) {
            return $value;
        }
    }

    return '';
}

/**
 * Return a human-readable file size.
 *
 * @param int $bytes
 * @return string
 */
function local_sentaldocupload_course_record_filesize(int $bytes): string {
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Return a course image URL when Moodle has one.
 *
 * @param stdClass $course
 * @return string
 */
function local_sentaldocupload_course_record_image(stdClass $course): string {
    global $OUTPUT, $PAGE;

    try {
        if (class_exists('\\core_course\\external\\course_summary_exporter')) {
            $image = \core_course\external\course_summary_exporter::get_course_image($course);
            if ($image instanceof moodle_url) {
                return $image->out(false);
            }
            if (is_string($image) && trim($image) !== '') {
                return trim($image);
            }
        }
    } catch (Throwable $e) {
        // Continue to the file area fallback.
    }

    $coursecontext = context_course::instance((int)$course->id, IGNORE_MISSING);
    if ($coursecontext) {
        try {
            $fs = get_file_storage();
            $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'sortorder, filepath, filename', false);
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                if (method_exists($file, 'is_valid_image') && !$file->is_valid_image()) {
                    continue;
                }
                return moodle_url::make_pluginfile_url(
                    $coursecontext->id,
                    'course',
                    'overviewfiles',
                    0,
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                )->out(false);
            }
        } catch (Throwable $e) {
            // Continue to generated image fallback.
        }
    }

    foreach ([$OUTPUT, $PAGE->get_renderer('core')] as $renderer) {
        try {
            if ($renderer && method_exists($renderer, 'get_generated_image_for_id')) {
                $generated = $renderer->get_generated_image_for_id((int)$course->id);
                if ($generated instanceof moodle_url) {
                    return $generated->out(false);
                }
                if (is_string($generated) && trim($generated) !== '') {
                    return trim($generated);
                }
            }
        } catch (Throwable $e) {
            // Use CSS fallback.
        }
    }

    return '';
}

/**
 * Return stored file size for a document viewer target.
 *
 * @param string $component
 * @param string $filearea
 * @param int $itemid
 * @return int
 */
function local_sentaldocupload_course_record_file_size_for(string $component, string $filearea, int $itemid): int {
    $fs = get_file_storage();
    $files = $fs->get_area_files(context_system::instance()->id, $component, $filearea, $itemid, 'id DESC', false);
    $file = reset($files);
    return $file ? (int)$file->get_filesize() : 0;
}

/**
 * Build one UI document object.
 *
 * @param string $title
 * @param string $url
 * @param int $bytes
 * @param int|null $issuedate
 * @param int|null $expirydate
 * @return stdClass
 */
function local_sentaldocupload_course_record_document(
    string $title,
    string $url,
    int $bytes,
    ?int $issuedate,
    ?int $expirydate
): stdClass {
    return (object)[
        'title' => $title,
        'url' => $url,
        'size' => local_sentaldocupload_course_record_filesize($bytes),
        'issuedate' => $issuedate,
        'expirydate' => $expirydate,
        'status' => local_sentaldocupload_get_status($expirydate, true),
    ];
}

/**
 * Resolve documents for the selected learner/course.
 *
 * @param int $userid
 * @param int $courseid
 * @return array{completion:?stdClass,supplementary:array}
 */
function local_sentaldocupload_course_record_documents(int $userid, int $courseid): array {
    global $DB;

    $completiondoc = null;
    $supplementary = [];
    $validitydays = local_sentaldocupload_get_course_validity_days($courseid);

    if ($DB->get_manager()->table_exists('local_ncasign_jobs')) {
        $ncasignsql = "SELECT j.id AS jobid,
                              j.documenttitle,
                              j.timecreated AS jobtimecreated,
                              j.manualcompleted,
                              j.autosigned,
                              f.filename AS signedfilename,
                              f.timecreated AS filetimecreated,
                              f.timemodified AS filetimemodified,
                              cc.timecompleted AS completiontime
                         FROM {local_ncasign_jobs} j
                         JOIN {files} f ON f.component = :component
                                        AND f.filearea = :filearea
                                        AND f.itemid = j.id
                                        AND f.filename <> :dot
                    LEFT JOIN {course_completions} cc ON cc.course = j.courseid
                                                     AND cc.userid = j.userid
                        WHERE j.userid = :userid
                          AND j.courseid = :courseid
                          AND j.status IN (:completedmanual, :completedauto)
                          AND j.origin <> :demoorigin
                     ORDER BY j.timecreated DESC, f.id DESC";
        $ncarecord = $DB->get_record_sql($ncasignsql, [
            'component' => 'local_ncasign',
            'filearea' => 'signedpdf',
            'dot' => '.',
            'userid' => $userid,
            'courseid' => $courseid,
            'completedmanual' => 'completed_manual',
            'completedauto' => 'completed_auto',
            'demoorigin' => 'demo_job',
        ], IGNORE_MULTIPLE);

        if ($ncarecord) {
            $jobid = (int)$ncarecord->jobid;
            $title = trim((string)($ncarecord->documenttitle ?: $ncarecord->signedfilename));
            if ($title === '') {
                $title = get_string('coursecompletiondocument', 'local_sentaldocupload');
            }
            if (strtolower(pathinfo($title, PATHINFO_EXTENSION)) !== 'pdf') {
                $title .= '.pdf';
            }
            $issuedate = (int)($ncarecord->completiontime ?: $ncarecord->manualcompleted ?: $ncarecord->autosigned
                ?: $ncarecord->filetimemodified ?: $ncarecord->jobtimecreated);
            $completiondoc = local_sentaldocupload_course_record_document(
                $title,
                (new moodle_url('/local/sentaldocupload/viewer.php', ['ncasignjobid' => $jobid]))->out(false),
                local_sentaldocupload_course_record_file_size_for('local_ncasign', 'signedpdf', $jobid),
                $issuedate,
                local_sentaldocupload_calculate_expiry($issuedate, $validitydays)
            );
        }
    }

    $docsql = "SELECT d.id AS documentid,
                      d.documenttype,
                      d.customlabel,
                      v.id AS versionid,
                      v.versionno,
                      v.filename,
                      v.customlabel AS versionlabel,
                      v.issuedate,
                      v.expirydate,
                      v.timecreated
                 FROM {sental_modeb_doc_user} du
                 JOIN {sental_modeb_doc} d ON d.id = du.documentid
                 JOIN {sental_modeb_doc_version} v ON v.documentid = d.id
                WHERE du.userid = :userid
                  AND d.courseid = :courseid
                  AND d.documenttype IN ('type1', 'type2')
                  AND v.versionno = d.currentversion
             ORDER BY d.documenttype ASC, v.timecreated DESC";

    foreach ($DB->get_records_sql($docsql, ['userid' => $userid, 'courseid' => $courseid]) as $record) {
        $title = trim((string)($record->versionlabel ?: $record->customlabel ?: $record->filename));
        if ($title === '') {
            $title = get_string('file', 'local_sentaldocupload');
        }
        $doc = local_sentaldocupload_course_record_document(
            $title,
            (new moodle_url('/local/sentaldocupload/viewer.php', ['versionid' => (int)$record->versionid]))->out(false),
            local_sentaldocupload_course_record_file_size_for('local_sentaldocupload', 'document', (int)$record->versionid),
            empty($record->issuedate) ? null : (int)$record->issuedate,
            empty($record->expirydate) ? null : (int)$record->expirydate
        );

        if ((string)$record->documenttype === 'type1') {
            if (!$completiondoc) {
                $completiondoc = $doc;
            }
        } else {
            $supplementary[] = $doc;
        }
    }

    return ['completion' => $completiondoc, 'supplementary' => $supplementary];
}

/**
 * Resolve score and pass mark.
 *
 * @param int $userid
 * @param int $courseid
 * @return array{score:?int,passing:int,passed:bool}
 */
function local_sentaldocupload_course_record_score(int $userid, int $courseid): array {
    global $DB;

    $score = null;
    $passing = 70;

    try {
        $courseitem = grade_item::fetch_course_item($courseid);
        if ($courseitem) {
            if (!empty($courseitem->gradepass) && !empty($courseitem->grademax)) {
                $passing = (int)round(((float)$courseitem->gradepass / (float)$courseitem->grademax) * 100);
            }
            $grade = grade_get_course_grade($userid, $courseid);
            $finalgrade = null;
            if ($grade && isset($grade->grade) && is_numeric($grade->grade)) {
                $finalgrade = (float)$grade->grade;
            } else if ($grade && isset($grade->finalgrade) && is_numeric($grade->finalgrade)) {
                $finalgrade = (float)$grade->finalgrade;
            }
            if ($finalgrade !== null && !empty($courseitem->grademax)) {
                $score = (int)round(($finalgrade / (float)$courseitem->grademax) * 100);
            }
        }
    } catch (Throwable $e) {
        $score = null;
    }

    if ($score === null && $DB->get_manager()->table_exists('local_iomad_track')) {
        $trackscore = $DB->get_field('local_iomad_track', 'finalscore', [
            'userid' => $userid,
            'courseid' => $courseid,
        ], IGNORE_MULTIPLE);
        if ($trackscore !== false && is_numeric($trackscore)) {
            $score = (int)round((float)$trackscore);
        }
    }

    return [
        'score' => $score,
        'passing' => max(0, min(100, $passing)),
        'passed' => $score !== null && $score >= $passing,
    ];
}

/**
 * Resolve start and completion timestamps.
 *
 * @param int $userid
 * @param int $courseid
 * @return array{started:?int,completed:?int}
 */
function local_sentaldocupload_course_record_dates(int $userid, int $courseid): array {
    global $DB;

    $completion = $DB->get_record('course_completions', [
        'userid' => $userid,
        'course' => $courseid,
    ], 'id,timeenrolled,timestarted,timecompleted', IGNORE_MISSING);

    $started = $completion ? (int)($completion->timestarted ?: $completion->timeenrolled) : 0;
    $completed = $completion ? (int)$completion->timecompleted : 0;

    if ((!$started || !$completed) && $DB->get_manager()->table_exists('local_iomad_track')) {
        $track = $DB->get_record('local_iomad_track', [
            'userid' => $userid,
            'courseid' => $courseid,
        ], 'id,timeenrolled,timestarted,timecompleted', IGNORE_MISSING);
        if ($track) {
            $started = $started ?: (int)($track->timestarted ?: $track->timeenrolled);
            $completed = $completed ?: (int)$track->timecompleted;
        }
    }

    if (!$started) {
        $started = (int)$DB->get_field_sql(
            "SELECT MIN(ue.timestart)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid
                AND e.courseid = :courseid
                AND ue.timestart > 0",
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    return [
        'started' => $started ?: null,
        'completed' => $completed ?: null,
    ];
}

/**
 * Render a document card.
 *
 * @param stdClass|null $document
 * @param string $emptytext
 * @return string
 */
function local_sentaldocupload_course_record_doc_card(?stdClass $document, string $emptytext): string {
    $icon = '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">'
        . '<path d="M14 3v5h5"/><path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/>'
        . '<path d="M9 13h6M9 17h4"/></svg>';
    $eye = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">'
        . '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';

    if (!$document) {
        return html_writer::div(
            html_writer::div($icon, 'sental-course-record-doc-icon') .
            html_writer::div(html_writer::tag('b', s($emptytext)), 'sental-course-record-doc-text'),
            'sental-course-record-doc is-empty'
        );
    }

    $meta = $document->size !== '' ? s($document->size) : get_string('file', 'local_sentaldocupload');
    return html_writer::div(
        html_writer::div($icon, 'sental-course-record-doc-icon') .
        html_writer::div(
            html_writer::tag('b', s($document->title)) .
            html_writer::tag('small', $meta),
            'sental-course-record-doc-text'
        ) .
        html_writer::link($document->url, $eye . html_writer::span(get_string('viewdocument', 'local_sentaldocupload')), [
            'class' => 'sental-course-record-btn primary',
        ]),
        'sental-course-record-doc'
    );
}

$documents = local_sentaldocupload_course_record_documents($userid, $courseid);
$hasdocs = !empty($documents['completion']) || !empty($documents['supplementary']);
if (!$isownrecord && !$canviewdocuments) {
    throw new required_capability_exception($context, 'local/sentaldocupload:viewdocuments', 'nopermissions', 'error');
}
if ($isownrecord && !$isenrolled && !$hasdocs) {
    throw new required_capability_exception($context, 'local/sentaldocupload:viewdocuments', 'nopermissions', 'error');
}

$site = local_sentaldocupload_course_record_user_info($userid, ['site']);
$position = local_sentaldocupload_course_record_user_info($userid, ['job_title', 'jobtitle', 'position', 'occupation']);
$fullname = fullname($learner);
$initials = core_text::strtoupper(core_text::substr((string)$learner->firstname, 0, 1) . core_text::substr((string)$learner->lastname, 0, 1));
$initials = $initials !== '' ? $initials : core_text::strtoupper(core_text::substr((string)$learner->username, 0, 2));

$score = local_sentaldocupload_course_record_score($userid, $courseid);
$dates = local_sentaldocupload_course_record_dates($userid, $courseid);
$completiondoc = $documents['completion'];
$status = $completiondoc ? (string)$completiondoc->status : 'nodocument';

$formatdate = static function(?int $timestamp): string {
    return empty($timestamp) ? '-' : userdate($timestamp, get_string('strftimedate', 'langconfig'));
};

$durationseconds = ($dates['started'] && $dates['completed'] && $dates['completed'] > $dates['started'])
    ? $dates['completed'] - $dates['started']
    : 0;
$durationhours = (int)floor($durationseconds / HOURSECS);
$durationminutes = (int)floor(($durationseconds % HOURSECS) / MINSECS);

$issuedate = $completiondoc ? $completiondoc->issuedate : null;
$expirydate = $completiondoc ? $completiondoc->expirydate : null;
$validitydays = local_sentaldocupload_get_course_validity_days($courseid);
$validityprogress = 0;
if ($issuedate && $expirydate && $expirydate > $issuedate) {
    $validityprogress = (int)round(((time() - $issuedate) / ($expirydate - $issuedate)) * 100);
    $validityprogress = max(0, min(100, $validityprogress));
}
$validitygreen = $expirydate ? min($validityprogress, 88) : 100;
$validityyellow = $expirydate ? max(0, 100 - $validitygreen) : 0;

$daysleft = $expirydate ? (int)ceil(($expirydate - time()) / DAYSECS) : null;
$statuslabel = get_string('statusnodocument', 'local_sentaldocupload');
if ($status === 'active') {
    $statuslabel = get_string('statusactive', 'local_sentaldocupload');
} else if ($status === 'expiring') {
    $statuslabel = get_string('statusexpiring', 'local_sentaldocupload') . ($daysleft !== null ? ' - ' . max(0, $daysleft) . ' days left' : '');
} else if ($status === 'expired') {
    $statuslabel = get_string('statusexpired', 'local_sentaldocupload');
}

$heroimage = local_sentaldocupload_course_record_image($course);
$heroimagestyle = $heroimage !== ''
    ? '--sental-course-record-image:url("' . s(str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "", ""], $heroimage)) . '");'
    : '--sental-course-record-image:none;';

$pageparams = ['courseid' => $courseid];
if (!$isownrecord || $canviewdocuments) {
    $pageparams['userid'] = $userid;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/sentaldocupload/course_record.php', $pageparams));
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/sentaldocupload/styles.css'));

echo $OUTPUT->header();

echo html_writer::start_div('sental-course-record');
echo html_writer::div(
    html_writer::link(new moodle_url('/local/sentaldocupload/mydocuments.php'), '&lt; ' . get_string('certifications', 'local_sentaldocupload'), [
        'class' => 'sental-course-record-back',
    ]),
    'sental-course-record-crumbs'
);

echo html_writer::start_tag('section', ['class' => 'sental-course-record-hero status-' . s($status), 'style' => $heroimagestyle]);
echo html_writer::start_div('sental-course-record-hero-grid');
echo html_writer::start_div('sental-course-record-hero-left');
echo html_writer::div('Employee course record', 'sental-course-record-eyebrow');
echo html_writer::tag('h1', format_string($course->fullname));
echo html_writer::div(
    html_writer::span(s($initials), 'sental-course-record-avatar') .
    html_writer::div(
        html_writer::tag('b', s($fullname)) .
        html_writer::tag('small', s(trim(($site !== '' ? $site : 'Site') . ' - ' . ($position !== '' ? $position : 'Position'), ' -')))
    ),
    'sental-course-record-person'
);
echo html_writer::div(html_writer::span('', 'dot') . html_writer::span(s($statuslabel)), 'sental-course-record-pill ' . s($status));
echo html_writer::end_div();

echo html_writer::start_div('sental-course-record-score');
echo html_writer::div('Final score', 'sental-course-record-score-label');
$scorevalue = $score['score'];
$scorepercent = $scorevalue === null ? 0 : max(0, min(100, $scorevalue));
$circumference = 326.7;
$offset = $circumference - ($circumference * $scorepercent / 100);
echo html_writer::div(
    '<svg width="118" height="118" viewBox="0 0 118 118">'
    . '<circle cx="59" cy="59" r="52" fill="none" stroke="rgba(255,255,255,.16)" stroke-width="11"/>'
    . '<circle cx="59" cy="59" r="52" fill="none" stroke="#4ADE80" stroke-width="11" stroke-linecap="round" stroke-dasharray="326.7" stroke-dashoffset="' . s((string)$offset) . '"/>'
    . '</svg>'
    . html_writer::div(html_writer::tag('b', $scorevalue === null ? '-' : (string)$scorevalue) . html_writer::tag('i', '/ 100'), 'sental-course-record-score-value'),
    'sental-course-record-ring'
);
echo html_writer::div($score['passed'] ? '&#10003; Test passed' : 'Passing score not reached', 'sental-course-record-pass');
echo html_writer::div('Passing score: ' . html_writer::tag('b', (int)$score['passing'] . '%'), 'sental-course-record-threshold');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('section');

echo html_writer::start_div('sental-course-record-row');
echo html_writer::start_tag('section', ['class' => 'sental-course-record-card']);
echo html_writer::start_div('sental-course-record-group');
echo html_writer::tag('h2', get_string('coursecompletiondocument', 'local_sentaldocupload'));
echo html_writer::div(
    local_sentaldocupload_course_record_doc_card($completiondoc, get_string('nodocumentsfortype', 'local_sentaldocupload')),
    'sental-course-record-doc-list'
);
echo html_writer::end_div();
echo html_writer::start_div('sental-course-record-group');
echo html_writer::tag('h2', get_string('doctype_type2_short', 'local_sentaldocupload'));
echo html_writer::start_div('sental-course-record-doc-list');
if ($documents['supplementary']) {
    foreach ($documents['supplementary'] as $document) {
        echo local_sentaldocupload_course_record_doc_card($document, '');
    }
} else {
    echo local_sentaldocupload_course_record_doc_card(null, get_string('nodocumentsfortype', 'local_sentaldocupload'));
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('section');

echo html_writer::start_tag('section', ['class' => 'sental-course-record-card']);
echo html_writer::tag('h2', 'Time to complete');
echo html_writer::div(
    html_writer::span(
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3.5 2.2"/></svg>',
        'sental-course-record-clock'
    ) .
    html_writer::div(
        html_writer::div(
            s((string)$durationhours) . ' ' . html_writer::span('h', 'unit') . ' ' .
            s((string)$durationminutes) . ' ' . html_writer::span('min', 'unit'),
            'sental-course-record-duration'
        ) .
        html_writer::div('total learning duration', 'sental-course-record-caption')
    ),
    'sental-course-record-time-top'
);
echo html_writer::div(
    html_writer::div(html_writer::span('', 'sental-course-record-step-dot') . html_writer::div(html_writer::tag('small', 'Started') . html_writer::tag('b', s($formatdate($dates['started'])))), 'sental-course-record-step') .
    html_writer::div(html_writer::span('', 'sental-course-record-step-dot') . html_writer::div(html_writer::tag('small', 'Completed') . html_writer::tag('b', s($formatdate($dates['completed'])))), 'sental-course-record-step done'),
    'sental-course-record-steps'
);
echo html_writer::end_tag('section');
echo html_writer::end_div();

echo html_writer::start_tag('section', ['class' => 'sental-course-record-card sental-course-record-validity']);
echo html_writer::tag('h2', 'Document validity');
echo html_writer::start_div('sental-course-record-timeline');
if ($completiondoc) {
    echo html_writer::div(
        html_writer::div('today - ' . s($formatdate(time())), 'lbl') .
        html_writer::div('', 'needle') .
        html_writer::div('', 'dotm'),
        'sental-course-record-timeline-mark',
        ['style' => 'left:' . $validityprogress . '%']
    );
}
echo html_writer::div(
    html_writer::div('', 'seg active', ['style' => 'width:' . $validitygreen . '%']) .
    html_writer::div('', 'seg expiring', ['style' => 'width:' . $validityyellow . '%']),
    'sental-course-record-timeline-bar'
);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::div(html_writer::span('Issued') . html_writer::tag('b', s($formatdate($issuedate)))) .
    html_writer::div(html_writer::span('Valid until') . html_writer::tag('b', s($formatdate($expirydate))), 'right'),
    'sental-course-record-timeline-ends'
);
echo html_writer::div(
    html_writer::span(html_writer::tag('i', '', ['class' => 'active']) . 'Active') .
    html_writer::span(html_writer::tag('i', '', ['class' => 'expiring']) . 'Expiry window - 30 days') .
    html_writer::span('Validity period: ' . html_writer::tag('b', $validitydays > 0 ? s((string)$validitydays) . ' days' : get_string('noexpiry', 'local_sentaldocupload'))),
    'sental-course-record-legend'
);
echo html_writer::end_tag('section');

echo html_writer::end_div();

echo $OUTPUT->footer();
