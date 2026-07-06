define([], function() {
    function option(value, label) {
        var item = document.createElement('option');
        item.value = value;
        item.textContent = label;
        return item;
    }

    function hasValue(element) {
        return element && element.value !== null && String(element.value).trim() !== '';
    }

    return {
        init: function(coursesUrl, expiryUrl, sesskey) {
            var user = document.getElementById('id_userid');
            var course = document.getElementById('id_courseid');
            var issueDate = document.getElementById('id_issuedate');
            var file = document.getElementById('id_documentfile');
            var submit = document.getElementById('id_submitbutton');
            var preview = document.getElementById('id_expirypreview');

            function setPreview(text, type) {
                if (!preview) {
                    return;
                }
                preview.textContent = text;
                preview.className = 'alert alert-' + (type || 'info');
            }

            function updateAvailability() {
                var canPickFile = hasValue(user) && hasValue(course) && hasValue(issueDate);
                if (file) {
                    file.disabled = !canPickFile;
                    if (!canPickFile) {
                        file.value = '';
                    }
                }
                if (submit) {
                    submit.disabled = !(canPickFile && file && file.files && file.files.length > 0);
                }
            }

            function resetCourses(label) {
                if (!course) {
                    return;
                }
                course.innerHTML = '';
                course.appendChild(option('', label || 'Select user first'));
                course.disabled = true;
            }

            function loadCourses() {
                if (!user || !course) {
                    return;
                }
                resetCourses('Loading courses...');
                setPreview('Select course and issue date to calculate expiry date.', 'info');
                updateAvailability();

                if (!hasValue(user)) {
                    resetCourses('Select user first');
                    updateAvailability();
                    return;
                }

                var xhr = new XMLHttpRequest();
                xhr.open('GET', coursesUrl + '?sesskey=' + encodeURIComponent(sesskey) + '&userid=' + encodeURIComponent(user.value));
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) {
                        return;
                    }
                    course.innerHTML = '';
                    if (xhr.status !== 200) {
                        course.appendChild(option('', 'Could not load courses'));
                        course.disabled = true;
                        updateAvailability();
                        return;
                    }

                    var data;
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch (e) {
                        course.appendChild(option('', 'Could not load courses'));
                        course.disabled = true;
                        updateAvailability();
                        return;
                    }

                    if (!data.courses || !data.courses.length) {
                        course.appendChild(option('', 'This user has no courses'));
                        course.disabled = true;
                        updateAvailability();
                        return;
                    }

                    course.appendChild(option('', 'Select course'));
                    data.courses.forEach(function(item) {
                        var label = item.fullname + ' (Validity days: ' + item.validitydays + ')';
                        course.appendChild(option(item.id, label));
                    });
                    course.disabled = false;
                    updateAvailability();
                };
                xhr.send();
            }

            function calculateExpiry() {
                updateAvailability();
                if (!hasValue(course) || !hasValue(issueDate)) {
                    setPreview('Select course and issue date to calculate expiry date.', 'info');
                    return;
                }

                setPreview('Calculating expiry date...', 'info');
                var xhr = new XMLHttpRequest();
                xhr.open('GET', expiryUrl + '?sesskey=' + encodeURIComponent(sesskey) + '&courseid=' + encodeURIComponent(course.value) + '&issuedate=' + encodeURIComponent(issueDate.value));
                xhr.onreadystatechange = function() {
                    if (xhr.readyState !== 4) {
                        return;
                    }
                    if (xhr.status !== 200) {
                        setPreview('Could not calculate expiry date.', 'danger');
                        return;
                    }
                    try {
                        var data = JSON.parse(xhr.responseText);
                        setPreview(data.message || 'Expiry date calculated.', data.success ? 'success' : 'warning');
                    } catch (e) {
                        setPreview('Could not calculate expiry date.', 'danger');
                    }
                };
                xhr.send();
            }

            if (user) {
                user.addEventListener('change', loadCourses);
            }
            if (course) {
                course.addEventListener('change', calculateExpiry);
            }
            if (issueDate) {
                issueDate.addEventListener('change', calculateExpiry);
                issueDate.addEventListener('input', calculateExpiry);
            }
            if (file) {
                file.addEventListener('change', updateAvailability);
            }

            resetCourses('Select user first');
            updateAvailability();
        }
    };
});
