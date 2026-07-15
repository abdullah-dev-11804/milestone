<?php
// Unified full-screen in-app document viewer for My Certifications and Public Profile.
// Replace: local/sentaldocupload/viewer.php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

$versionid = optional_param('versionid', 0, PARAM_INT);
$ncasignjobid = optional_param('ncasignjobid', 0, PARAM_INT);
$public = optional_param('public', 0, PARAM_BOOL);
$userid = optional_param('userid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$context = context_system::instance();

$record = null;
$file = null;
$canmanage = false;
$ispublicviewer = false;
$isncasignviewer = false;

if ($public) {
    if (empty($versionid) || empty($userid) || empty($courseid)) {
        throw new moodle_exception('filenotfound');
    }

    // Use the same Public Profile visibility helper used by the profile cards.
    // This keeps Public Profile, View Document, and file serving logic aligned.
    $publicrecords = local_sentaldocupload_get_public_profile_scans($userid, $courseid);
    foreach ($publicrecords as $publicrecord) {
        if ((int)$publicrecord->versionid === $versionid) {
            $record = $publicrecord;
            $ispublicviewer = true;
            break;
        }
    }
    if (!$record) {
        throw new moodle_exception('filenotfound');
    }
} else {
    require_login();
    $canmanage = has_capability('local/sentaldocupload:manage', $context);

    if ($ncasignjobid > 0) {
        $job = $DB->get_record('local_ncasign_jobs', ['id' => $ncasignjobid], '*', IGNORE_MISSING);
        if (!$job) {
            throw new moodle_exception('filenotfound');
        }

        $coursecontext = context_course::instance((int)$job->courseid, IGNORE_MISSING);
        $ownsdocument = ((int)$job->userid === (int)$USER->id);
        $isenrolledincourse = ($coursecontext && is_enrolled($coursecontext, $USER, '', true));
        if (!$canmanage && !$ownsdocument && !$isenrolledincourse) {
            throw new required_capability_exception($context, 'local/sentaldocupload:manage', 'nopermissions', 'error');
        }

        $course = $DB->get_record('course', ['id' => (int)$job->courseid], 'id,fullname,shortname', IGNORE_MISSING);
        $coursefullname = $course
            ? (string)$course->fullname
            : get_string('courseunavailable', 'local_sentaldocupload', (int)$job->courseid);
        $courseshortname = $course ? (string)$course->shortname : '';

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_ncasign', 'signedpdf', $ncasignjobid, 'id DESC', false);
        $file = reset($files);
        if (!$file) {
            throw new moodle_exception('filenotfound');
        }

        $completiontime = (int)$DB->get_field('course_completions', 'timecompleted', [
            'course' => (int)$job->courseid,
            'userid' => (int)$job->userid,
        ], IGNORE_MISSING);
        $issuedate = (int)($completiontime ?: $job->manualcompleted ?: $job->autosigned ?: $job->timecreated);
        $validitydays = local_sentaldocupload_get_course_validity_days((int)$job->courseid);
        $expirydate = local_sentaldocupload_calculate_expiry($issuedate, $validitydays);

        $record = (object)[
            'id' => -$ncasignjobid,
            'versionid' => -$ncasignjobid,
            'documentid' => -$ncasignjobid,
            'versionno' => 1,
            'filename' => $file->get_filename(),
            'issuedate' => $issuedate,
            'expirydate' => $expirydate,
            'courseid' => (int)$job->courseid,
            'documenttype' => 'type1',
            'coursefullname' => $coursefullname,
            'courseshortname' => $courseshortname,
            'userid' => (int)$job->userid,
        ];
        $isncasignviewer = true;
    } else {
        if (empty($versionid)) {
            throw new moodle_exception('filenotfound');
        }

        $sql = "SELECT v.id AS id,
                       v.id AS versionid,
                       v.documentid,
                       v.versionno,
                       v.filename,
                       v.issuedate,
                       v.expirydate,
                       d.courseid,
                       d.documenttype,
                       c.fullname AS coursefullname,
                       c.shortname AS courseshortname
                  FROM {sental_modeb_doc_version} v
                  JOIN {sental_modeb_doc} d ON d.id = v.documentid
                  JOIN {course} c ON c.id = d.courseid
                 WHERE v.id = :versionid";
        $record = $DB->get_record_sql($sql, ['versionid' => $versionid], IGNORE_MISSING);
        if (!$record) {
            throw new moodle_exception('filenotfound');
        }

        $ownsdocument = $DB->record_exists('sental_modeb_doc_user', [
            'documentid' => (int)$record->documentid,
            'userid' => (int)$USER->id,
        ]);
        $record->userid = $ownsdocument
            ? (int)$USER->id
            : (int)$DB->get_field('sental_modeb_doc_user', 'userid', ['documentid' => (int)$record->documentid], IGNORE_MULTIPLE);

        if (!$canmanage && !$ownsdocument) {
            throw new required_capability_exception($context, 'local/sentaldocupload:manage', 'nopermissions', 'error');
        }
    }
}

$fs = get_file_storage();
if (!$file) {
    $files = $fs->get_area_files($context->id, 'local_sentaldocupload', 'document', $versionid, 'filename', false);
    $file = reset($files);
}
if (!$file) {
    throw new moodle_exception('filenotfound');
}

$filename = $file->get_filename();
$mimetype = $file->get_mimetype();

$status = local_sentaldocupload_get_status(empty($record->expirydate) ? null : (int)$record->expirydate, true);
$formatdate = static function($timestamp) {
    return empty($timestamp) ? get_string('noexpiry', 'local_sentaldocupload') : userdate((int)$timestamp, get_string('strftimedate', 'langconfig'));
};

$params = $isncasignviewer ? ['ncasignjobid' => $ncasignjobid] : ['versionid' => $versionid];
if ($ispublicviewer) {
    $params['public'] = 1;
    $params['userid'] = $userid;
    $params['courseid'] = $courseid;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/sentaldocupload/viewer.php', $params));
$PAGE->set_title(get_string('documentviewer', 'local_sentaldocupload'));
$PAGE->set_heading(get_string('documentviewer', 'local_sentaldocupload'));
$PAGE->set_pagelayout('embedded');
$PAGE->requires->css(new moodle_url('/local/sentaldocupload/styles.css'));

if ($isncasignviewer) {
    $previewurl = new moodle_url('/local/ncasign/download_artifact.php', [
        'jobid' => $ncasignjobid,
        'type' => 'signedpdf',
        'inline' => 1,
    ]);
    $downloadurl = new moodle_url('/local/ncasign/download_artifact.php', [
        'jobid' => $ncasignjobid,
        'type' => 'signedpdf',
    ]);
} else if ($ispublicviewer) {
    $previewurl = new moodle_url('/local/sentaldocupload/publicfile.php', [
        'versionid' => $versionid,
        'userid' => $userid,
        'courseid' => $courseid,
        'preview' => 1,
        'inline' => 1,
    ]);
    $downloadurl = new moodle_url('/local/sentaldocupload/publicfile.php', [
        'versionid' => $versionid,
        'userid' => $userid,
        'courseid' => $courseid,
    ]);
} else {
    $previewurl = new moodle_url('/local/sentaldocupload/download.php', ['versionid' => $versionid, 'preview' => 1, 'inline' => 1]);
    $downloadurl = new moodle_url('/local/sentaldocupload/download.php', ['versionid' => $versionid]);
}

$shareurl = $PAGE->url->out(false);
$isimage = strpos($mimetype, 'image/') === 0;
$ispdf = ($mimetype === 'application/pdf' || preg_match('/\.pdf$/i', $filename));

$downloadtext = get_string('downloadfile', 'local_sentaldocupload');
$copytext = get_string('copysharelink', 'local_sentaldocupload');
$copiedtext = get_string('copied', 'local_sentaldocupload');
$viewinbrowsertext = get_string('viewinbrowser', 'local_sentaldocupload');
$fallbacktext = get_string('browserviewfallback', 'local_sentaldocupload');
$backtext = get_string('back');

// Prefer plugin language string if it exists, otherwise keep a short PDF label.
$pdftext = get_string_manager()->string_exists('pdfbutton', 'local_sentaldocupload')
    ? get_string('pdfbutton', 'local_sentaldocupload')
    : 'PDF';
$pageindicator = get_string_manager()->string_exists('pageindicator', 'local_sentaldocupload')
    ? get_string('pageindicator', 'local_sentaldocupload', ['page' => '{page}', 'total' => '{total}'])
    : 'Page {page} / {total}';


// Prefer Moodle's bundled PDF.js when available, with a CDN fallback.
$pdfjsscripturl = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
$pdfjsworkerurl = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
foreach ([
    ['/lib/pdfjs/build/pdf.min.js', '/lib/pdfjs/build/pdf.worker.min.js'],
    ['/lib/pdfjs/build/pdf.js', '/lib/pdfjs/build/pdf.worker.js'],
] as $candidate) {
    if (file_exists($CFG->dirroot . $candidate[0]) && file_exists($CFG->dirroot . $candidate[1])) {
        $pdfjsscripturl = $CFG->wwwroot . $candidate[0];
        $pdfjsworkerurl = $CFG->wwwroot . $candidate[1];
        break;
    }
}

// The document viewer intentionally bypasses Moodle's header, footer and theme wrappers.
// On iOS Safari those wrappers can change the visual viewport and canvas containing block,
// causing otherwise correctly rendered PDF pages to appear offset or misaligned.
\core\session\manager::write_close();
@header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="<?php echo s(current_language()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<title><?php echo s($filename); ?></title>
<style>
* {
    box-sizing: border-box;
}
html,
body {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    padding: 0;
    overflow: hidden;
    background: #1e2a3a;
    color: #1e2a3a;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    -webkit-text-size-adjust: 100%;
}
button,
a {
    font: inherit;
}
#sv-shell {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #1e2a3a;
    padding-top: env(safe-area-inset-top, 0px);
    padding-bottom: env(safe-area-inset-bottom, 0px);
}
#sv-bar {
    flex: 0 0 auto;
    min-height: 54px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px max(10px, env(safe-area-inset-right, 0px)) 9px max(10px, env(safe-area-inset-left, 0px));
    background: #fff;
    border-bottom: 1px solid #d6dde2;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .18);
    z-index: 5;
}
#sv-title {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    color: #1e2a3a;
    font-size: 14px;
    font-weight: 800;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.sv-btn {
    flex: 0 0 auto;
    min-height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    padding: 7px 11px;
    border: 1px solid transparent;
    font-size: 12px;
    line-height: 1;
    font-weight: 800;
    text-decoration: none !important;
    white-space: nowrap;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -webkit-tap-highlight-color: transparent;
}
.sv-btn-back,
.sv-btn-share,
.sv-btn-native {
    background: #f2f5f7;
    border-color: #c9d3da;
    color: #1e2a3a !important;
}
.sv-btn-dl {
    background: #75b84f;
    border-color: #75b84f;
    color: #fff !important;
}
.sv-btn-copied {
    background: #1e2a3a;
    border-color: #1e2a3a;
    color: #fff !important;
}
#sv-main {
    flex: 1 1 0;
    min-height: 0;
    position: relative;
    overflow: hidden;
    background: #2c3e52;
}
#sv-scroll {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    padding: 18px 10px 28px;
    text-align: center;
}
#sv-pages {
    width: 100%;
}
.sv-page-wrap {
    width: 100%;
    margin: 0 auto 18px;
    text-align: center;
}
.sv-page-label {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    margin: 0 auto 7px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .14);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
.sv-page-canvas,
#sv-image {
    display: block;
    margin: 0 auto;
    max-width: 100%;
    height: auto;
    background: #fff;
    border-radius: 3px;
    box-shadow: 0 5px 18px rgba(0, 0, 0, .45);
}
#sv-loader {
    position: absolute;
    inset: 0;
    z-index: 4;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 18px;
    background: #2c3e52;
    color: #fff;
    text-align: center;
    font-size: 14px;
    font-weight: 700;
}
.sv-spinner {
    width: 42px;
    height: 42px;
    border: 4px solid rgba(255, 255, 255, .25);
    border-top-color: #fff;
    border-radius: 50%;
    animation: sv-spin .75s linear infinite;
}
@keyframes sv-spin {
    to { transform: rotate(360deg); }
}
#sv-native {
    display: none;
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
    background: #fff;
}
#sv-error {
    display: none;
    max-width: 760px;
    margin: 0 auto 14px;
    padding: 12px 14px;
    border-radius: 10px;
    background: #fff3cd;
    color: #664d03;
    text-align: left;
    font-weight: 700;
    font-size: 13px;
}
@media (max-width: 520px) {
    #sv-bar {
        gap: 5px;
        min-height: 48px;
        padding-top: 7px;
        padding-bottom: 7px;
    }
    #sv-title {
        display: none;
    }
    .sv-btn {
        min-height: 31px;
        padding: 6px 7px;
        border-radius: 7px;
        font-size: 11px;
    }
    #sv-scroll {
        padding: 12px max(6px, env(safe-area-inset-right, 0px)) calc(22px + env(safe-area-inset-bottom, 0px)) max(6px, env(safe-area-inset-left, 0px));
    }
    .sv-page-wrap {
        margin-bottom: 14px;
    }
}
@media (max-width: 360px) {
    .sv-btn {
        padding-left: 5px;
        padding-right: 5px;
        font-size: 10px;
    }
}
</style>
</head>
<body>
<div id="sv-shell"
     data-share-url="<?php echo s($shareurl); ?>"
     data-pdf-url="<?php echo s($previewurl->out(false)); ?>"
     data-is-pdf="<?php echo $ispdf ? '1' : '0'; ?>"
     data-page-template="<?php echo s($pageindicator); ?>">

    <div id="sv-bar">
        <button type="button" id="sv-back" class="sv-btn sv-btn-back"><?php echo s($backtext); ?></button>

        <div id="sv-title" title="<?php echo s($filename); ?>"><?php echo s($filename); ?></div>

        <button type="button"
                id="sv-copy-link"
                class="sv-btn sv-btn-share"
                data-original-text="<?php echo s($copytext); ?>"
                data-copied-text="<?php echo s($copiedtext); ?>">
            <?php echo s($copytext); ?>
        </button>

        <?php if ($ispdf) { ?>
            <button type="button" id="sv-native-btn" class="sv-btn sv-btn-native"><?php echo s($pdftext); ?></button>
        <?php } else { ?>
            <a href="<?php echo s($previewurl->out(false)); ?>" target="_blank" rel="noopener" class="sv-btn sv-btn-native">
                <?php echo s($viewinbrowsertext); ?>
            </a>
        <?php } ?>

        <a href="<?php echo s($downloadurl->out(false)); ?>" class="sv-btn sv-btn-dl"><?php echo s($downloadtext); ?></a>
    </div>

    <main id="sv-main">
        <div id="sv-loader">
            <div class="sv-spinner"></div>
            <div id="sv-loader-text"><?php echo s(get_string('loading', 'admin')); ?></div>
        </div>

        <div id="sv-scroll">
            <div id="sv-error"></div>
            <?php if ($isimage) { ?>
                <img id="sv-image" src="<?php echo s($previewurl->out(false)); ?>" alt="<?php echo s($filename); ?>">
            <?php } else if ($ispdf) { ?>
                <div id="sv-pages"></div>
            <?php } ?>
        </div>

        <iframe id="sv-native"
                src="<?php echo (!$isimage && !$ispdf) ? s($previewurl->out(false)) : 'about:blank'; ?>"
                title="<?php echo s($filename); ?>"></iframe>
    </main>
</div>

<script>
(function () {
    'use strict';

    var shell = document.getElementById('sv-shell');
    var backBtn = document.getElementById('sv-back');
    var copyBtn = document.getElementById('sv-copy-link');
    var loader = document.getElementById('sv-loader');
    var loaderText = document.getElementById('sv-loader-text');
    var errorBox = document.getElementById('sv-error');
    var pages = document.getElementById('sv-pages');
    var scrollEl = document.getElementById('sv-scroll');
    var nativeFrame = document.getElementById('sv-native');
    var nativeBtn = document.getElementById('sv-native-btn');
    var image = document.getElementById('sv-image');

    var PDFJS_SCRIPT = <?php echo json_encode($pdfjsscripturl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var PDFJS_WORKER = <?php echo json_encode($pdfjsworkerurl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var FALLBACK_TEXT = <?php echo json_encode($fallbacktext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function hideLoader() {
        if (loader) {
            loader.style.display = 'none';
        }
    }

    function setLoading(text) {
        if (loader) {
            loader.style.display = 'flex';
        }
        if (loaderText && text) {
            loaderText.textContent = text;
        }
    }

    function showError(message) {
        if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = message || FALLBACK_TEXT;
        }
    }

    function showCanvasViewer() {
        if (nativeFrame) {
            nativeFrame.style.display = 'none';
        }
        if (scrollEl) {
            scrollEl.style.display = 'block';
        }
    }

    function showNativeViewer(reason) {
        if (reason) {
            console.warn('[sental document viewer]', reason);
        }
        hideLoader();
        if (scrollEl) {
            scrollEl.style.display = 'none';
        }
        if (nativeFrame) {
            nativeFrame.style.display = 'block';
            if (nativeFrame.getAttribute('src') === 'about:blank') {
                var pdfUrl = shell.getAttribute('data-pdf-url');
                nativeFrame.src = pdfUrl + '#page=1&view=FitH&toolbar=1&navpanes=0';
            }
        }
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            var completed = false;
            var timer = window.setTimeout(function () {
                if (completed) {
                    return;
                }
                completed = true;
                reject(new Error('PDF.js loading timeout'));
            }, 10000);

            script.src = src;
            script.onload = function () {
                if (completed) {
                    return;
                }
                completed = true;
                window.clearTimeout(timer);
                resolve();
            };
            script.onerror = function () {
                if (completed) {
                    return;
                }
                completed = true;
                window.clearTimeout(timer);
                reject(new Error('PDF.js script failed'));
            };
            document.head.appendChild(script);
        });
    }

    function getAvailableWidth() {
        if (!scrollEl) {
            return Math.max(280, Math.min(window.innerWidth - 12, 980));
        }

        var style = window.getComputedStyle(scrollEl);
        var leftPadding = parseFloat(style.paddingLeft) || 0;
        var rightPadding = parseFloat(style.paddingRight) || 0;
        var width = scrollEl.getBoundingClientRect().width - leftPadding - rightPadding;
        return Math.max(220, Math.min(Math.floor(width), 980));
    }

    async function renderPdf() {
        var pdfUrl = shell.getAttribute('data-pdf-url');
        var pageTemplate = shell.getAttribute('data-page-template') || 'Page {page} / {total}';

        setLoading('Loading document…');
        if (!window.pdfjsLib) {
            await loadScript(PDFJS_SCRIPT);
        }
        if (!window.pdfjsLib) {
            throw new Error('PDF.js library not available');
        }

        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;

        var pdf = await window.pdfjsLib.getDocument({
            url: pdfUrl,
            withCredentials: true,
            disableRange: true,
            disableStream: true,
            disableAutoFetch: true,
            isEvalSupported: false
        }).promise;

        var total = pdf.numPages || 0;
        if (!total || !pages) {
            throw new Error('No PDF pages were found');
        }

        pages.innerHTML = '';
        showCanvasViewer();
        if (errorBox) {
            errorBox.style.display = 'none';
        }

        // The CSS page width is calculated once from the actual scroll viewport.
        // Each canvas is rendered at that exact CSS size. Device pixel ratio only
        // increases the backing bitmap; it never changes layout width on iOS.
        var availableWidth = getAvailableWidth();

        for (var pageNo = 1; pageNo <= total; pageNo++) {
            setLoading('Rendering ' + pageNo + ' / ' + total + '…');

            var page = await pdf.getPage(pageNo);
            var baseViewport = page.getViewport({ scale: 1 });
            var cssScale = availableWidth / baseViewport.width;
            cssScale = Math.max(0.35, Math.min(cssScale, 1.75));
            var cssViewport = page.getViewport({ scale: cssScale });
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            var renderViewport = page.getViewport({ scale: cssScale * dpr });

            var wrap = document.createElement('section');
            wrap.className = 'sv-page-wrap';

            var label = document.createElement('div');
            label.className = 'sv-page-label';
            label.textContent = pageTemplate.replace('{page}', pageNo).replace('{total}', total);
            wrap.appendChild(label);

            var canvas = document.createElement('canvas');
            canvas.className = 'sv-page-canvas';

            var cssWidth = Math.floor(cssViewport.width);
            var cssHeight = Math.floor(cssViewport.height);
            canvas.width = Math.max(1, Math.floor(renderViewport.width));
            canvas.height = Math.max(1, Math.floor(renderViewport.height));
            canvas.style.width = cssWidth + 'px';
            canvas.style.height = cssHeight + 'px';

            wrap.appendChild(canvas);
            pages.appendChild(wrap);

            var context = canvas.getContext('2d', { alpha: false });
            await page.render({
                canvasContext: context,
                viewport: renderViewport
            }).promise;
        }

        hideLoader();
    }

    if (backBtn) {
        backBtn.addEventListener('click', function () {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.close();
            }
        });
    }

    if (copyBtn && shell) {
        copyBtn.addEventListener('click', function (event) {
            event.preventDefault();
            var shareUrl = shell.getAttribute('data-share-url') || window.location.href;
            var originalText = copyBtn.getAttribute('data-original-text') || copyBtn.textContent;
            var copiedText = copyBtn.getAttribute('data-copied-text') || 'Copied';

            function markCopied() {
                copyBtn.textContent = copiedText;
                copyBtn.classList.add('sv-btn-copied');
                window.setTimeout(function () {
                    copyBtn.textContent = originalText;
                    copyBtn.classList.remove('sv-btn-copied');
                }, 1800);
            }

            function fallbackCopy(text) {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', 'readonly');
                textarea.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                try {
                    document.execCommand('copy');
                } catch (e) {
                    // Ignore and leave the user on the viewer.
                }
                document.body.removeChild(textarea);
            }

            if (navigator.share) {
                navigator.share({ title: document.title, url: shareUrl }).catch(function () {});
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shareUrl).then(markCopied).catch(function () {
                    fallbackCopy(shareUrl);
                    markCopied();
                });
            } else {
                fallbackCopy(shareUrl);
                markCopied();
            }
        });
    }

    if (nativeBtn) {
        nativeBtn.addEventListener('click', function () {
            showNativeViewer('manual native viewer');
        });
    }

    if (image) {
        image.addEventListener('load', hideLoader);
        image.addEventListener('error', function () {
            showError(FALLBACK_TEXT);
            hideLoader();
        });
        window.setTimeout(hideLoader, 2500);
    } else if (shell && shell.getAttribute('data-is-pdf') === '1') {
        renderPdf().catch(function (error) {
            console.error('[sental document viewer]', error);
            showError(FALLBACK_TEXT);
            showNativeViewer(error && error.message ? error.message : 'PDF.js failed');
        });
    } else {
        if (nativeFrame) {
            nativeFrame.style.display = 'block';
            nativeFrame.addEventListener('load', hideLoader);
        }
        window.setTimeout(hideLoader, 2500);
    }
})();
</script>
</body>
</html>
<?php
exit;
