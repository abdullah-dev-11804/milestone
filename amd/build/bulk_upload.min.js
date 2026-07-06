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

    function containsCompany(optionElement, companyId) {
        if (!companyId) {
            return true;
        }
        var companyIds = (optionElement.getAttribute('data-companyids') || '').split(',');
        return companyIds.indexOf(String(companyId)) !== -1;
    }

    function show(node) {
        if (node) {
            node.style.display = '';
            node.setAttribute('aria-hidden', 'false');
        }
    }

    function hide(node) {
        if (node) {
            node.style.display = 'none';
            node.setAttribute('aria-hidden', 'true');
        }
    }

    return {
        init: function(coursesUrl, expiryUrl, sesskey, preferredCourseId) {
            var company = document.getElementById('id_companyid');
            var userInput = document.getElementById('id_usercombo');
            var userDropdown = document.getElementById('id_user_dropdown');
            var user = document.getElementById('id_userid');
            var course = document.getElementById('id_courseid');
            var issueDate = document.getElementById('id_issuedate');
            var submit = document.getElementById('id_submitbutton');
            var preview = document.getElementById('id_expirypreview');
            var addRowButton = document.getElementById('id_add_document_row');
            var noResultsNode = null;
            var courseParticipants = {};
            var selectedCourseHasEds = false;
            preferredCourseId = parseInt(preferredCourseId || 0, 10) || 0;

            function setPreview(text, type) {
                if (preview) {
                    preview.textContent = text;
                    preview.className = 'alert alert-' + (type || 'info');
                }
            }

            function visibleRows() {
                return Array.prototype.slice.call(document.querySelectorAll('.sental-document-row')).filter(function(row) {
                    return row.style.display !== 'none';
                });
            }

            function updateRowButtons() {
                var rows = Array.prototype.slice.call(document.querySelectorAll('.sental-document-row'));
                var hiddenRows = rows.filter(function(row) { return row.style.display === 'none'; });
                if (addRowButton) {
                    addRowButton.disabled = hiddenRows.length === 0;
                    addRowButton.style.display = hiddenRows.length === 0 ? 'none' : '';
                }
            }

            function updateAvailability() {
                var canPickFiles = hasValue(user) && hasValue(course) && hasValue(issueDate);
                Array.prototype.slice.call(document.querySelectorAll('.sental-filemanager-wrap')).forEach(function(wrap) {
                    if (canPickFiles) {
                        wrap.classList.remove('sental-filemanager-disabled');
                    } else {
                        wrap.classList.add('sental-filemanager-disabled');
                    }
                });
                if (submit) {
                    submit.disabled = !canPickFiles;
                }
                updateRowButtons();
            }

            function resetCourses(label) {
                if (!course) {
                    return;
                }
                course.innerHTML = '';
                course.appendChild(option('', label || 'Select user first'));
                course.disabled = true;
                selectedCourseHasEds = false;
                courseParticipants = {};
                refreshAllParticipantDropdowns();
            }

            function getUserOptions() {
                if (!userDropdown) {
                    return [];
                }
                return Array.prototype.slice.call(userDropdown.querySelectorAll('.sental-user-option'));
            }

            function closeUserDropdown() {
                if (userDropdown) {
                    userDropdown.hidden = true;
                }
            }

            function openUserDropdown() {
                if (userDropdown) {
                    userDropdown.hidden = false;
                }
            }

            function ensureNoResultsNode() {
                if (!userDropdown) {
                    return null;
                }
                if (!noResultsNode) {
                    noResultsNode = document.createElement('div');
                    noResultsNode.className = 'sental-user-no-results';
                    noResultsNode.textContent = 'No users found';
                    userDropdown.appendChild(noResultsNode);
                }
                return noResultsNode;
            }

            function filterUsers(openList) {
                var selectedCompany = company ? company.value : '';
                var term = userInput ? String(userInput.value || '').toLowerCase().trim() : '';
                var visibleCount = 0;

                getUserOptions().forEach(function(opt) {
                    var searchText = String(opt.getAttribute('data-search') || opt.textContent || '').toLowerCase();
                    var matchesCompany = containsCompany(opt, selectedCompany);
                    var matchesSearch = !term || searchText.indexOf(term) !== -1;
                    var showOption = matchesCompany && matchesSearch;
                    opt.hidden = !showOption;
                    if (showOption) {
                        visibleCount++;
                    }
                });

                var empty = ensureNoResultsNode();
                if (empty) {
                    empty.hidden = visibleCount !== 0;
                }
                if (openList) {
                    openUserDropdown();
                }
            }

            function clearSelectedUser() {
                if (user) {
                    user.value = '';
                }
                resetCourses('Select user first');
                setPreview('Select course and issue date to calculate expiry date.', 'info');
                updateAvailability();
            }

            function selectUserFromOption(opt) {
                if (!opt || !user || !userInput) {
                    return;
                }
                user.value = opt.getAttribute('data-userid') || '';
                userInput.value = opt.getAttribute('data-label') || opt.textContent || '';
                closeUserDropdown();
                loadCourses();
            }

            function getSelectedCourseParticipants() {
                if (!course || !course.value || !courseParticipants[course.value]) {
                    return [];
                }
                return courseParticipants[course.value];
            }

            function getParticipantElements(rownum) {
                return {
                    combo: document.getElementById('id_participant_combo' + rownum),
                    input: document.getElementById('id_participant_combo_input' + rownum),
                    dropdown: document.getElementById('id_participant_dropdown' + rownum),
                    selected: document.getElementById('id_participant_selected' + rownum),
                    hidden: document.getElementById('id_participant_hidden' + rownum)
                };
            }

            function getSelectedParticipantIds(rownum) {
                var els = getParticipantElements(rownum);
                if (!els.hidden) {
                    return [];
                }
                return Array.prototype.slice.call(els.hidden.querySelectorAll('input[type="hidden"]')).map(function(input) {
                    return String(input.value);
                });
            }

            function closeParticipantDropdown(rownum) {
                var els = getParticipantElements(rownum);
                if (els.dropdown) {
                    els.dropdown.hidden = true;
                }
            }

            function openParticipantDropdown(rownum) {
                var els = getParticipantElements(rownum);
                if (els.dropdown) {
                    els.dropdown.hidden = false;
                }
            }

            function filterParticipantDropdown(rownum, openList) {
                var els = getParticipantElements(rownum);
                if (!els.dropdown) {
                    return;
                }

                var term = els.input ? String(els.input.value || '').toLowerCase().trim() : '';
                var selectedIds = getSelectedParticipantIds(rownum);
                var visibleCount = 0;

                Array.prototype.slice.call(els.dropdown.querySelectorAll('.sental-participant-option')).forEach(function(optionNode) {
                    var search = String(optionNode.getAttribute('data-search') || optionNode.textContent || '').toLowerCase();
                    var userid = String(optionNode.getAttribute('data-userid') || '');
                    var showOption = selectedIds.indexOf(userid) === -1 && (!term || search.indexOf(term) !== -1);
                    optionNode.hidden = !showOption;
                    if (showOption) {
                        visibleCount++;
                    }
                });

                var noResults = els.dropdown.querySelector('.sental-participant-no-results');
                if (noResults) {
                    noResults.hidden = visibleCount !== 0;
                }
                if (openList) {
                    openParticipantDropdown(rownum);
                }
            }

            function renderParticipantDropdown(row) {
                var rownum = row.getAttribute('data-row');
                var els = getParticipantElements(rownum);
                if (!els.dropdown) {
                    return;
                }

                els.dropdown.innerHTML = '';
                var participants = getSelectedCourseParticipants();
                if (!course || !course.value) {
                    var selectFirst = document.createElement('div');
                    selectFirst.className = 'sental-participant-empty';
                    selectFirst.textContent = 'Select course first.';
                    els.dropdown.appendChild(selectFirst);
                    return;
                }
                if (!participants.length) {
                    var empty = document.createElement('div');
                    empty.className = 'sental-participant-empty';
                    empty.textContent = 'No additional enrolled users found for this course.';
                    els.dropdown.appendChild(empty);
                    return;
                }

                participants.forEach(function(participant) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'sental-participant-option';
                    button.setAttribute('data-userid', participant.id);
                    button.setAttribute('data-label', participant.label);
                    button.setAttribute('data-search', participant.search || String(participant.label || '').toLowerCase());
                    button.setAttribute('data-row', rownum);
                    button.textContent = participant.label;
                    els.dropdown.appendChild(button);
                });

                var noResults = document.createElement('div');
                noResults.className = 'sental-participant-empty sental-participant-no-results';
                noResults.textContent = 'No matching users found';
                noResults.hidden = true;
                els.dropdown.appendChild(noResults);

                filterParticipantDropdown(rownum, false);
            }

            function refreshAllParticipantDropdowns() {
                Array.prototype.slice.call(document.querySelectorAll('.sental-document-row')).forEach(function(row) {
                    renderParticipantDropdown(row);
                });
            }

            function addSelectedParticipant(rownum, participantId, label) {
                var els = getParticipantElements(rownum);
                if (!els.selected || !els.hidden || !participantId) {
                    return;
                }
                participantId = String(participantId);
                if (getSelectedParticipantIds(rownum).indexOf(participantId) !== -1) {
                    return;
                }

                var chip = document.createElement('span');
                chip.className = 'sental-participant-chip';
                chip.setAttribute('data-userid', participantId);
                chip.appendChild(document.createTextNode(label));

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'sental-participant-chip-remove';
                remove.setAttribute('aria-label', 'Remove ' + label);
                remove.setAttribute('data-row', rownum);
                remove.setAttribute('data-userid', participantId);
                remove.textContent = '×';
                chip.appendChild(remove);

                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'participants' + rownum + '[]';
                hidden.value = participantId;
                hidden.setAttribute('data-userid', participantId);

                els.selected.appendChild(chip);
                els.hidden.appendChild(hidden);
                if (els.input) {
                    els.input.value = '';
                    els.input.focus();
                }
                filterParticipantDropdown(rownum, true);
            }

            function removeSelectedParticipant(rownum, participantId) {
                var els = getParticipantElements(rownum);
                if (!els.selected || !els.hidden) {
                    return;
                }
                Array.prototype.slice.call(els.selected.querySelectorAll('.sental-participant-chip')).forEach(function(chip) {
                    if (String(chip.getAttribute('data-userid')) === String(participantId)) {
                        chip.remove();
                    }
                });
                Array.prototype.slice.call(els.hidden.querySelectorAll('input[type="hidden"]')).forEach(function(input) {
                    if (String(input.getAttribute('data-userid')) === String(participantId)) {
                        input.remove();
                    }
                });
                filterParticipantDropdown(rownum, true);
            }

            function clearParticipantSelections(row) {
                var rownum = row.getAttribute('data-row');
                var els = getParticipantElements(rownum);
                if (els.selected) {
                    els.selected.innerHTML = '';
                }
                if (els.hidden) {
                    els.hidden.innerHTML = '';
                }
                if (els.input) {
                    els.input.value = '';
                }
                closeParticipantDropdown(rownum);
            }

            function selectParticipantFromOption(optionNode) {
                if (!optionNode) {
                    return;
                }
                addSelectedParticipant(
                    optionNode.getAttribute('data-row'),
                    optionNode.getAttribute('data-userid'),
                    optionNode.getAttribute('data-label') || optionNode.textContent || ''
                );
            }

            function updateSelectedCourseEdsFlag() {
                if (!course || !course.value || !course.options.length || course.selectedIndex < 0) {
                    selectedCourseHasEds = false;
                    return;
                }
                var selected = course.options[course.selectedIndex];
                selectedCourseHasEds = !!(selected && selected.getAttribute('data-haseds') === '1');
            }

            function updateDocumentTypeUI() {
                updateSelectedCourseEdsFlag();
                Array.prototype.slice.call(document.querySelectorAll('.sental-document-row')).forEach(function(row) {
                    var rownum = row.getAttribute('data-row');
                    var documentType = document.getElementById('id_documenttype' + rownum);
                    var customWrap = document.getElementById('id_customlabel_wrap' + rownum);
                    var publicWrap = document.getElementById('id_showpublic_wrap' + rownum);
                    var publicCheckbox = document.getElementById('id_showinpublic' + rownum);
                    if (!documentType) {
                        return;
                    }

                    if (documentType.value === 'type2') {
                        show(customWrap);
                        hide(publicWrap);
                        if (publicCheckbox) {
                            publicCheckbox.checked = false;
                        }
                        return;
                    }

                    hide(customWrap);
                    if (hasValue(user) && hasValue(course) && !selectedCourseHasEds) {
                        show(publicWrap);
                        // Do not auto-check this. Admin must explicitly tick it to show the document to the student/public profile.
                    } else {
                        hide(publicWrap);
                        if (publicCheckbox) {
                            publicCheckbox.checked = false;
                        }
                    }
                });
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
                var separator = coursesUrl.indexOf('?') === -1 ? '?' : '&';
                var url = coursesUrl + separator + 'sesskey=' + encodeURIComponent(sesskey) + '&userid=' + encodeURIComponent(user.value);
                if (company && company.value) {
                    url += '&companyid=' + encodeURIComponent(company.value);
                }
                xhr.open('GET', url);
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
                        course.appendChild(option('', data.message || 'This user has no courses'));
                        course.disabled = true;
                        updateAvailability();
                        return;
                    }

                    courseParticipants = {};
                    course.appendChild(option('', 'Select course'));
                    data.courses.forEach(function(item) {
                        var label = item.fullname + ' - ' + item.uploadpath + ' (Validity days: ' + item.validitydays + ')';
                        courseParticipants[item.id] = item.participants || [];
                        var courseOption = option(item.id, label);
                        courseOption.setAttribute('data-haseds', item.haseds ? '1' : '0');
                        course.appendChild(courseOption);
                    });
                    course.disabled = false;
                    if (preferredCourseId) {
                        var preferredOption = Array.prototype.slice.call(course.options).filter(function(opt) {
                            return String(opt.value) === String(preferredCourseId);
                        })[0];
                        if (preferredOption) {
                            course.value = String(preferredCourseId);
                        }
                    } else if (data.courses.length === 1) {
                        course.value = String(data.courses[0].id);
                    }
                    refreshAllParticipantDropdowns();
                    updateDocumentTypeUI();
                    calculateExpiry();
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

                updateSelectedCourseEdsFlag();
                updateDocumentTypeUI();
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
                    updateAvailability();
                };
                xhr.send();
            }

            function showNextDocumentRow() {
                var rows = Array.prototype.slice.call(document.querySelectorAll('.sental-document-row'));
                var next = rows.filter(function(row) { return row.style.display === 'none'; })[0];
                if (!next) {
                    updateRowButtons();
                    return;
                }
                next.style.display = '';
                next.setAttribute('aria-hidden', 'false');
                renderParticipantDropdown(next);
                updateDocumentTypeUI();
                updateRowButtons();
            }

            function hideDocumentRow(row) {
                if (!row) {
                    return;
                }
                clearParticipantSelections(row);
                row.style.display = 'none';
                row.setAttribute('aria-hidden', 'true');
                updateRowButtons();
            }

            if (userInput) {
                userInput.addEventListener('focus', function() {
                    filterUsers(true);
                });
                userInput.addEventListener('input', function() {
                    clearSelectedUser();
                    filterUsers(true);
                });
                userInput.addEventListener('keydown', function(event) {
                    if (event.key === 'ArrowDown') {
                        var first = getUserOptions().filter(function(opt) { return !opt.hidden; })[0];
                        if (first) {
                            first.focus();
                            event.preventDefault();
                        }
                    }
                    if (event.key === 'Enter') {
                        var firstVisible = getUserOptions().filter(function(opt) { return !opt.hidden; })[0];
                        if (firstVisible && !hasValue(user)) {
                            selectUserFromOption(firstVisible);
                            event.preventDefault();
                        }
                    }
                    if (event.key === 'Escape') {
                        closeUserDropdown();
                    }
                });
            }

            if (userDropdown) {
                userDropdown.addEventListener('click', function(event) {
                    var target = event.target.closest ? event.target.closest('.sental-user-option') : null;
                    if (target) {
                        selectUserFromOption(target);
                    }
                });
            }

            document.addEventListener('click', function(event) {
                var userWrap = document.getElementById('id_usercombo_wrap');
                if (userWrap && !userWrap.contains(event.target)) {
                    closeUserDropdown();
                }

                var participantOption = event.target.closest ? event.target.closest('.sental-participant-option') : null;
                if (participantOption) {
                    selectParticipantFromOption(participantOption);
                    event.preventDefault();
                    return;
                }

                var participantRemove = event.target.closest ? event.target.closest('.sental-participant-chip-remove') : null;
                if (participantRemove) {
                    removeSelectedParticipant(participantRemove.getAttribute('data-row'), participantRemove.getAttribute('data-userid'));
                    event.preventDefault();
                    return;
                }

                var removeRow = event.target.closest ? event.target.closest('.sental-remove-document-row') : null;
                if (removeRow) {
                    hideDocumentRow(removeRow.closest('.sental-document-row'));
                    event.preventDefault();
                    return;
                }

                Array.prototype.slice.call(document.querySelectorAll('.sental-participant-combo')).forEach(function(combo) {
                    if (!combo.contains(event.target)) {
                        closeParticipantDropdown(combo.getAttribute('data-row'));
                    }
                });
            });

            if (course) {
                course.addEventListener('change', function() {
                    Array.prototype.slice.call(document.querySelectorAll('.sental-document-row')).forEach(clearParticipantSelections);
                    refreshAllParticipantDropdowns();
                    updateDocumentTypeUI();
                    calculateExpiry();
                });
            }

            if (issueDate) {
                issueDate.addEventListener('change', calculateExpiry);
                issueDate.addEventListener('input', calculateExpiry);
            }

            if (addRowButton) {
                addRowButton.addEventListener('click', showNextDocumentRow);
            }

            document.addEventListener('change', function(event) {
                var typeSelect = event.target.closest ? event.target.closest('.sental-row-documenttype') : null;
                if (typeSelect) {
                    updateDocumentTypeUI();
                }
            });

            document.addEventListener('focusin', function(event) {
                var participantInput = event.target.closest ? event.target.closest('.sental-participant-combo-input') : null;
                if (participantInput) {
                    filterParticipantDropdown(participantInput.getAttribute('data-row'), true);
                }
            });

            document.addEventListener('input', function(event) {
                var participantInput = event.target.closest ? event.target.closest('.sental-participant-combo-input') : null;
                if (participantInput) {
                    filterParticipantDropdown(participantInput.getAttribute('data-row'), true);
                }
            });

            document.addEventListener('keydown', function(event) {
                var participantInput = event.target.closest ? event.target.closest('.sental-participant-combo-input') : null;
                if (!participantInput) {
                    return;
                }
                var rownum = participantInput.getAttribute('data-row');
                var els = getParticipantElements(rownum);
                if (event.key === 'Enter') {
                    var first = els.dropdown ? Array.prototype.slice.call(els.dropdown.querySelectorAll('.sental-participant-option')).filter(function(opt) { return !opt.hidden; })[0] : null;
                    if (first) {
                        selectParticipantFromOption(first);
                        event.preventDefault();
                    }
                }
                if (event.key === 'Escape') {
                    closeParticipantDropdown(rownum);
                }
            });

            resetCourses('Select user first');
            filterUsers(false);
            updateDocumentTypeUI();
            updateAvailability();
        }
    };
});
