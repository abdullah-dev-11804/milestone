<?php
// Unified full-screen in-app document viewer for My Certifications and Public Profile.
// Replace: local/sentaldocupload/viewer.php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');

$versionid = required_param('versionid', PARAM_INT);
$public = optional_param('public', 0, PARAM_BOOL);
$userid = optional_param('userid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$context = context_system::instance();

$record = null;
$canmanage = false;
$ispublicviewer = false;

if ($public) {
    if (empty($userid) || empty($courseid)) {
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
                   c.shortname AS courseshortname,
                   du.userid
              FROM {sental_modeb_doc_version} v
              JOIN {sental_modeb_doc} d ON d.id = v.documentid
              JOIN {course} c ON c.id = d.courseid
              JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
             WHERE v.id = :versionid";
    $records = $DB->get_records_sql($sql, ['versionid' => $versionid]);
    if (!$records) {
        throw new moodle_exception('filenotfound');
    }

    $ownsdocument = false;
    foreach ($records as $candidate) {
        if ((int)$candidate->userid === (int)$USER->id) {
            $record = $candidate;
            $ownsdocument = true;
            break;
        }
    }
    if (!$record) {
        $record = reset($records);
    }

    if (!$canmanage && !$ownsdocument) {
        throw new required_capability_exception($context, 'local/sentaldocupload:manage', 'nopermissions', 'error');
    }
}

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_sentaldocupload', 'document', $versionid, 'filename', false);
$file = reset($files);
if (!$file) {
    throw new moodle_exception('filenotfound');
}

$filename = $file->get_filename();
$mimetype = $file->get_mimetype();

$status = local_sentaldocupload_get_status(empty($record->expirydate) ? null : (int)$record->expirydate, true);
$formatdate = static function($timestamp) {
    return empty($timestamp) ? get_string('noexpiry', 'local_sentaldocupload') : userdate((int)$timestamp, get_string('strftimedate', 'langconfig'));
};

$params = ['versionid' => $versionid];
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

if ($ispublicviewer) {
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

$PAGE->requires->js_init_code(<<<JS
(function() {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function() {
        var shell = document.getElementById('sv-shell');
        var copyBtn = document.getElementById('sv-copy-link');
        var loader = document.getElementById('sv-loader');
        var errorBox = document.getElementById('sv-error');
        var pages = document.getElementById('sv-pages');
        var nativeFrame = document.getElementById('sv-native');
        var image = document.getElementById('sv-image');

        function hideLoader() {
            if (loader) {
                loader.style.display = 'none';
            }
        }

        function showError(message) {
            hideLoader();
            if (errorBox) {
                errorBox.style.display = 'block';
                errorBox.textContent = message;
            }
            if (nativeFrame) {
                nativeFrame.style.display = 'block';
            }
        }

        if (copyBtn && shell) {
            copyBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                var shareUrl = shell.getAttribute('data-share-url') || window.location.href;
                var originalText = copyBtn.getAttribute('data-original-text') || copyBtn.textContent;
                var copiedText = copyBtn.getAttribute('data-copied-text') || 'Copied';

                function markCopied() {
                    copyBtn.textContent = copiedText;
                    copyBtn.classList.add('sv-btn-copied');
                    setTimeout(function() {
                        copyBtn.textContent = originalText;
                        copyBtn.classList.remove('sv-btn-copied');
                    }, 1800);
                }

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(shareUrl);
                        markCopied();
                        return;
                    }

                    var textarea = document.createElement('textarea');
                    textarea.value = shareUrl;
                    textarea.setAttribute('readonly', 'readonly');
                    textarea.style.position = 'fixed';
                    textarea.style.top = '0';
                    textarea.style.left = '0';
                    textarea.style.width = '2em';
                    textarea.style.height = '2em';
                    textarea.style.padding = '0';
                    textarea.style.border = '0';
                    textarea.style.outline = '0';
                    textarea.style.boxShadow = 'none';
                    textarea.style.background = 'transparent';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.focus();
                    textarea.select();
                    textarea.setSelectionRange(0, textarea.value.length);
                    var copied = document.execCommand('copy');
                    document.body.removeChild(textarea);

                    if (copied) {
                        markCopied();
                        return;
                    }
                } catch (err) {
                    // Fall through to manual copy prompt below.
                }

                window.prompt('Copy this link:', shareUrl);
            });
        }

        if (image) {
            image.addEventListener('load', hideLoader);
            image.addEventListener('error', function() {
                showError('$fallbacktext');
            });
            setTimeout(hideLoader, 2500);
            return;
        }

        if (!shell || shell.getAttribute('data-is-pdf') !== '1') {
            if (nativeFrame) {
                nativeFrame.addEventListener('load', hideLoader);
                nativeFrame.style.display = 'block';
            }
            setTimeout(hideLoader, 2500);
            return;
        }

        var pdfUrl = shell.getAttribute('data-pdf-url');
        var pageTextTemplate = shell.getAttribute('data-page-template') || 'Page {page} / {total}';

        function loadScript(url) {
            return new Promise(function(resolve, reject) {
                var script = document.createElement('script');
                script.src = url;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        async function loadPdfJs() {
            if (window.pdfjsLib) {
                return window.pdfjsLib;
            }
            var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
            var urls = [
                root + '/lib/pdfjs/build/pdf.min.js',
                root + '/lib/pdfjs/build/pdf.js'
            ];
            for (var i = 0; i < urls.length; i++) {
                try {
                    await loadScript(urls[i]);
                    if (window.pdfjsLib) {
                        return window.pdfjsLib;
                    }
                } catch (e) {
                    // Try next possible Moodle PDF.js path.
                }
            }
            throw new Error('PDF.js could not be loaded');
        }

        async function renderPdf() {
            try {
                var pdfjsLib = await loadPdfJs();
                var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';

                try {
                    pdfjsLib.GlobalWorkerOptions.workerSrc = root + '/lib/pdfjs/build/pdf.worker.min.js';
                } catch (e) {
                    // Some PDF.js versions do not require this here.
                }

                var pdf = await pdfjsLib.getDocument({
                    url: pdfUrl,
                    withCredentials: true,
                    disableAutoFetch: false,
                    disableStream: false
                }).promise;

                if (!pages) {
                    throw new Error('Viewer target missing');
                }

                pages.innerHTML = '';
                var total = pdf.numPages;

                for (var pageNumber = 1; pageNumber <= total; pageNumber++) {
                    var page = await pdf.getPage(pageNumber);
                    var baseViewport = page.getViewport({scale: 1});
                    var containerWidth = Math.max(280, Math.min(pages.clientWidth || window.innerWidth, 1200));
                    var scale = containerWidth / baseViewport.width;
                    scale = Math.min(scale, window.devicePixelRatio && window.devicePixelRatio > 1 ? 2.2 : 1.8);
                    var viewport = page.getViewport({scale: scale});

                    var wrap = document.createElement('div');
                    wrap.className = 'sv-page-wrap';

                    var label = document.createElement('div');
                    label.className = 'sv-page-label';
                    label.textContent = pageTextTemplate.replace('{page}', pageNumber).replace('{total}', total);
                    wrap.appendChild(label);

                    var canvas = document.createElement('canvas');
                    canvas.className = 'sv-page-canvas';
                    canvas.width = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);
                    canvas.style.width = Math.floor(viewport.width / scale * Math.min(scale, 1)) + 'px';
                    canvas.style.maxWidth = '100%';
                    canvas.style.height = 'auto';
                    wrap.appendChild(canvas);
                    pages.appendChild(wrap);

                    await page.render({
                        canvasContext: canvas.getContext('2d'),
                        viewport: viewport
                    }).promise;
                }

                hideLoader();
            } catch (err) {
                if (nativeFrame) {
                    nativeFrame.src = pdfUrl;
                }
                showError('$fallbacktext');
            }
        }

        renderPdf();
    });
})();
JS, true);

echo $OUTPUT->header();
?>
<style>
    html, body {
        width: 100%;
        min-height: 100%;
        margin: 0;
        padding: 0;
    }
    body.path-local-sentaldocupload,
    body.path-local-sentaldocupload #page,
    body.path-local-sentaldocupload #page-wrapper,
    body.path-local-sentaldocupload #page-content,
    body.path-local-sentaldocupload #region-main,
    body.path-local-sentaldocupload [role="main"] {
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
        width: 100% !important;
        min-height: 100vh !important;
        background: #1e2a3a !important;
        overflow: hidden !important;
    }
    body.path-local-sentaldocupload #region-main > .card,
    body.path-local-sentaldocupload #region-main > .card > .card-body {
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }
    body.path-local-sentaldocupload header,
    body.path-local-sentaldocupload footer,
    body.path-local-sentaldocupload .navbar,
    body.path-local-sentaldocupload #page-header,
    body.path-local-sentaldocupload .breadcrumb,
    body.path-local-sentaldocupload .secondary-navigation,
    body.path-local-sentaldocupload .drawer-toggles,
    body.path-local-sentaldocupload .activity-header {
        display: none !important;
    }
    #sv-shell, #sv-shell * {
        box-sizing: border-box;
    }
    #sv-shell {
        width: 100%;
        height: 100vh;
        height: 100dvh;
        display: flex;
        flex-direction: column;
        background: #1e2a3a;
        color: #1e2a3a;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        padding-top: env(safe-area-inset-top, 0px);
        padding-bottom: env(safe-area-inset-bottom, 0px);
        overflow: hidden;
    }
    #sv-bar {
        flex: 0 0 auto;
        min-height: 54px;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 9px 10px;
        background: #fff;
        border-bottom: 1px solid #d6dde2;
        box-shadow: 0 2px 10px rgba(0,0,0,.18);
        z-index: 5;
    }
    #sv-title {
        flex: 1 1 auto;
        min-width: 0;
        font-size: 14px;
        font-weight: 800;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: #1e2a3a;
    }
    .sv-btn {
        flex: 0 0 auto;
        min-height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        padding: 7px 11px;
        font-size: 12px;
        line-height: 1;
        font-weight: 800;
        border: 1px solid transparent;
        text-decoration: none !important;
        cursor: pointer;
        white-space: nowrap;
        -webkit-tap-highlight-color: transparent;
        appearance: none;
    }
    .sv-btn-back, .sv-btn-share, .sv-btn-native {
        background: #f2f5f7;
        border-color: #c9d3da;
        color: #1e2a3a !important;
    }
    .sv-btn-dl, .sv-btn-copied {
        background: #1e2a3a;
        border-color: #1e2a3a;
        color: #fff !important;
    }
    .sv-btn-dl {
        background: #75b84f;
        border-color: #75b84f;
    }
    #sv-main {
        flex: 1 1 auto;
        min-height: 0;
        position: relative;
        overflow: hidden;
        background: #2c3e52;
    }
    #sv-scroll {
        width: 100%;
        height: 100%;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        padding: 18px 10px 28px;
        text-align: center;
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
        background: rgba(255,255,255,.14);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
    }
    .sv-page-canvas, #sv-image {
        display: block;
        margin: 0 auto;
        max-width: 100%;
        height: auto;
        background: #fff;
        border-radius: 3px;
        box-shadow: 0 5px 18px rgba(0,0,0,.45);
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
        border: 4px solid rgba(255,255,255,.25);
        border-top-color: #fff;
        border-radius: 50%;
        animation: sv-spin .75s linear infinite;
    }
    @keyframes sv-spin { to { transform: rotate(360deg); } }
    #sv-native {
        display: none;
        width: 100%;
        height: calc(100vh - 90px);
        height: calc(100dvh - 90px);
        border: 0;
        background: #fff;
        border-radius: 6px;
        box-shadow: 0 5px 18px rgba(0,0,0,.45);
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
        #sv-bar { gap: 5px; padding: 7px 6px; min-height: 48px; }
        #sv-title { font-size: 12px; }
        .sv-btn { min-height: 31px; padding: 6px 8px; font-size: 11px; border-radius: 7px; }
        #sv-scroll { padding: 12px 6px 22px; }
        .sv-page-wrap { margin-bottom: 14px; }
        #sv-native { height: calc(100vh - 72px); height: calc(100dvh - 72px); }
    }
</style>

<div id="sv-shell"
     data-share-url="<?php echo s($shareurl); ?>"
     data-pdf-url="<?php echo s($previewurl->out(false)); ?>"
     data-is-pdf="<?php echo $ispdf ? '1' : '0'; ?>"
     data-page-template="<?php echo s($pageindicator); ?>">

    <div id="sv-bar">
        <a href="javascript:history.back()" class="sv-btn sv-btn-back"><?php echo s($backtext); ?></a>

        <div id="sv-title" title="<?php echo s($filename); ?>">
            <?php echo s($filename); ?>
        </div>

        <button type="button"
                id="sv-copy-link"
                class="sv-btn sv-btn-share"
                data-original-text="<?php echo s($copytext); ?>"
                data-copied-text="<?php echo s($copiedtext); ?>">
            <?php echo s($copytext); ?>
        </button>

        <a href="<?php echo s($previewurl->out(false)); ?>" target="_blank" rel="noopener" class="sv-btn sv-btn-native">
            <?php echo s($pdftext); ?>
        </a>

        <a href="<?php echo s($downloadurl->out(false)); ?>" class="sv-btn sv-btn-dl">
            <?php echo s($downloadtext); ?>
        </a>
    </div>

    <div id="sv-main">
        <div id="sv-loader">
            <div class="sv-spinner"></div>
            <div><?php echo s(get_string('loading', 'admin')); ?></div>
        </div>

        <div id="sv-scroll">
            <div id="sv-error"></div>

            <?php if ($isimage) { ?>
                <img id="sv-image" src="<?php echo s($previewurl->out(false)); ?>" alt="<?php echo s($filename); ?>">
            <?php } else if ($ispdf) { ?>
                <div id="sv-pages"></div>
                <iframe id="sv-native" src="about:blank" title="<?php echo s($filename); ?>"></iframe>
            <?php } else { ?>
                <iframe id="sv-native" src="<?php echo s($previewurl->out(false)); ?>" title="<?php echo s($filename); ?>" style="display:block;"></iframe>
            <?php } ?>
        </div>
    </div>
</div>
<?php
// Keep Moodle footer for JS requirements, but it is hidden by the embedded/full-screen layout CSS above.
echo $OUTPUT->footer();
