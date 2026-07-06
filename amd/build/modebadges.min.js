define([], function() {
    function extractCourseId(url) {
        if (!url) {
            return null;
        }
        var match = url.match(/\/course\/view\.php\?id=(\d+)/);
        return match ? match[1] : null;
    }

    function findCourseTitle() {
        return document.querySelector('.page-header-headings h1') ||
            document.querySelector('#page-header h1') ||
            document.querySelector('.course-header h1') ||
            document.querySelector('.page-context-header h1') ||
            document.querySelector('h1');
    }

    function injectStyles() {
        if (document.getElementById('sental-course-mode-style')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'sental-course-mode-style';
        style.textContent = '' +
            '.sental-course-mode-top{' +
            'display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 12px 0;' +
            '}' +
            '.sental-course-mode-badge{' +
            'display:inline-flex!important;align-items:center;box-sizing:border-box;' +
            'width:auto!important;max-width:max-content!important;min-width:0!important;' +
            'margin:0;padding:6px 16px;border-radius:999px;' +
            'font-size:14px;font-weight:700;line-height:1.35;border:1px solid transparent;' +
            'white-space:nowrap;flex:0 0 auto!important;clear:none;' +
            '}' +
            '.sental-course-mode-badge.mode-scan{' +
            'color:#006b3c;background:#e6f7ee;border-color:#9bd8b9;' +
            '}' +
            '.sental-course-mode-badge.mode-eds{' +
            'color:#084298;background:#e7f1ff;border-color:#9ec5fe;' +
            '}';
        document.head.appendChild(style);
    }

    function removeOldCourseModeDuplicates() {
        // Remove only old SENTAL course-mode elements so the badge does not duplicate.
        // It will be added again after the AJAX mode check finishes.
        var nodes = document.querySelectorAll('.sental-course-mode-card-holder, .sental-course-mode-homepage, .sental-course-mode-top');
        Array.prototype.slice.call(nodes).forEach(function(node) {
            node.remove();
        });
    }

    function hideThemeCourseBadge() {
        // Remove only the theme's small COURSE pill, not our Course Mode badge.
        var title = findCourseTitle();
        var scopes = [];

        if (title) {
            var header = title.closest ? title.closest('#page-header, .page-header-headings, .course-header, .page-context-header, .header-main-content, .course-info-container, .coursebox') : null;
            if (header) {
                scopes.push(header);
            }
            if (title.parentNode) {
                scopes.push(title.parentNode);
                if (title.parentNode.parentNode) {
                    scopes.push(title.parentNode.parentNode);
                }
            }
        }

        var pageHeader = document.querySelector('#page-header');
        if (pageHeader) {
            scopes.push(pageHeader);
        }
        var regionMain = document.querySelector('#region-main');
        if (regionMain) {
            scopes.push(regionMain);
        }

        scopes.forEach(function(scope) {
            if (!scope) {
                return;
            }
            var candidates = scope.querySelectorAll('span, div, p, small, strong, label');
            Array.prototype.slice.call(candidates).forEach(function(node) {
                if (!node || !node.textContent) {
                    return;
                }
                if (node.classList && node.classList.contains('sental-course-mode-badge')) {
                    return;
                }
                if (node.closest && node.closest('.sental-course-mode-top')) {
                    return;
                }

                var text = node.textContent.replace(/\s+/g, ' ').trim().toUpperCase();
                if (text !== 'COURSE') {
                    return;
                }

                var rect = node.getBoundingClientRect ? node.getBoundingClientRect() : {width: 0, height: 0};
                // The theme COURSE badge is a small pill. This protects large layout containers.
                if ((rect.width && rect.width > 180) || (rect.height && rect.height > 70)) {
                    return;
                }
                node.remove();
            });
        });
    }

    function makeBadge(label, mode) {
        var span = document.createElement('span');
        span.className = 'sental-course-mode-badge mode-' + (mode === 'eds' ? 'eds' : 'scan');
        span.textContent = 'Course Mode: ' + label;
        return span;
    }

    function placeBadge(label, mode) {
        // Never hide the Course Mode badge. Remove only duplicate wrappers, then add it again.
        removeOldCourseModeDuplicates();
        hideThemeCourseBadge();

        var holder = document.createElement('div');
        holder.className = 'sental-course-mode-top';
        holder.appendChild(makeBadge(label, mode));

        var title = findCourseTitle();
        if (title && title.parentNode) {
            title.parentNode.insertBefore(holder, title);
            return;
        }

        var region = document.querySelector('#region-main') || document.body;
        region.insertBefore(holder, region.firstChild);
    }

    return {
        init: function(ajaxUrl, sesskey) {
            injectStyles();

            // Only on the real course homepage. No dashboard/course-card badges.
            var pageCourseId = extractCourseId(window.location.href);
            if (!pageCourseId) {
                removeOldCourseModeDuplicates();
                return;
            }

            hideThemeCourseBadge();
            // Some themes render the COURSE pill late, so remove it again after page load.
            setTimeout(hideThemeCourseBadge, 300);
            setTimeout(hideThemeCourseBadge, 1000);

            var xhr = new XMLHttpRequest();
            xhr.open('GET', ajaxUrl + '?sesskey=' + encodeURIComponent(sesskey) + '&ids=' + encodeURIComponent(pageCourseId));
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4 || xhr.status !== 200) {
                    return;
                }

                var data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    return;
                }

                if (data[pageCourseId]) {
                    placeBadge(data[pageCourseId].label, data[pageCourseId].mode);
                    setTimeout(hideThemeCourseBadge, 300);
                }
            };
            xhr.send();
        }
    };
});
