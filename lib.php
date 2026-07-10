<?php
// Library callbacks and helper functions for local_sentaldocupload.

defined('MOODLE_INTERNAL') || die();

/**
 * Return the NCASign template-course table name without Moodle prefix.
 * This is no longer used as a course-level mode flag. It only means that
 * the EDS upload/generation path is also available for that course.
 *
 * @return string
 */
function local_sentaldocupload_ncasign_table(): string {
    $configured = get_config('local_sentaldocupload', 'ncasigntablename');
    return $configured ?: 'local_ncasign_template_courses';
}

/**
 * Does the NCASign template-course table exist?
 *
 * @return bool
 */
function local_sentaldocupload_ncasign_table_exists(): bool {
    global $DB;
    return $DB->get_manager()->table_exists(local_sentaldocupload_ncasign_table());
}

/**
 * Check whether the selected course has an EDS template profile mapping.
 * Manual upload is still available regardless of this result.
 *
 * @param int $courseid
 * @return bool
 */
function local_sentaldocupload_course_has_eds_template_profile(int $courseid): bool {
    global $DB;
    $table = local_sentaldocupload_ncasign_table();
    if (!$DB->get_manager()->table_exists($table)) {
        return false;
    }
    return $DB->record_exists($table, ['courseid' => $courseid]);
}


/**
 * Best-effort check for an existing EDS document for a learner/course.
 *
 * The confirmed workflow says EDS documents take priority in Public Profile.
 * If the exact EDS document table is not available, we conservatively use the
 * EDS template profile mapping as the signal that the EDS path exists.
 *
 * @param int $courseid
 * @param int $userid
 * @return bool
 */
function local_sentaldocupload_user_course_has_eds_document(int $courseid, int $userid): bool {
    global $DB;

    // Project-confirmed EDS existence rule:
    // If this query returns a row, an EDS course-completion document/job exists
    // for the selected learner and course:
    //   local_ncasign_jobs.userid = selected user
    //   local_ncasign_jobs.courseid = selected course
    //   local_ncasign_jobs.origin = 'course_completion'
    if ($DB->get_manager()->table_exists('local_ncasign_jobs')) {
        $columns = $DB->get_columns('local_ncasign_jobs');
        if (isset($columns['userid']) && isset($columns['courseid']) && isset($columns['origin'])) {
            if ($DB->record_exists('local_ncasign_jobs', [
                'userid' => $userid,
                'courseid' => $courseid,
                'origin' => 'course_completion',
            ])) {
                return true;
            }
        }
    }

    // Fallback for older/sister NCASign installs that may store signed files in
    // a different table. The jobs table above is the source of truth when it exists.
    $candidates = [
        'local_ncasign_documents',
        'local_ncasign_document',
        'local_ncasign_signed_documents',
        'local_ncasign_signed_docs',
        'local_ncasign_user_documents',
        'local_ncasign_files',
    ];

    foreach ($candidates as $table) {
        if (!$DB->get_manager()->table_exists($table)) {
            continue;
        }
        $columns = $DB->get_columns($table);

        $coursefield = isset($columns['courseid']) ? 'courseid' : (isset($columns['course']) ? 'course' : null);
        $userfield = isset($columns['userid']) ? 'userid' : (isset($columns['user_id']) ? 'user_id' : null);
        if (!$coursefield || !$userfield) {
            continue;
        }

        $where = "$coursefield = :courseid AND $userfield = :userid";
        $params = ['courseid' => $courseid, 'userid' => $userid];

        if (isset($columns['origin'])) {
            $where .= " AND origin = :origin";
            $params['origin'] = 'course_completion';
        }
        if (isset($columns['deleted'])) {
            $where .= " AND deleted = 0";
        }
        if (isset($columns['status'])) {
            $where .= " AND status NOT IN ('draft', 'failed', 'rejected', 'deleted')";
        }
        if (isset($columns['signed'])) {
            $where .= " AND signed = 1";
        }

        if ($DB->record_exists_select($table, $where, $params)) {
            return true;
        }
    }

    return false;
}

/**
 * Decide whether a manual scan should be visible in Public Profile.
 *
 * Type 2 supplementary documents are never public. Type 1 scans are public
 * automatically only when no EDS document/path exists, or when admin explicitly
 * checks Show in Public Profile.
 *
 * @param string $documenttype type1|type2
 * @param int $courseid
 * @param int $userid
 * @param bool $adminoverride
 * @return int 1 public, 0 hidden
 */
function local_sentaldocupload_should_show_scan_in_public_profile(string $documenttype, int $courseid, int $userid, bool $adminselected = false): int {
    if ($documenttype !== 'type1') {
        return 0;
    }

    // EDS document takes priority. When an EDS course-completion job/document
    // exists, the manual scan is stored but not public and the UI hides the
    // checkbox. This server-side check also prevents forged POST values.
    if (local_sentaldocupload_user_course_has_eds_document($courseid, $userid)) {
        return 0;
    }

    // No EDS document exists: the Show in Public checkbox is available for
    // Type 1 and is checked by default in the upload UI. Respect the saved value.
    return $adminselected ? 1 : 0;
}

/**
 * Backwards-compatible helper for old code. It no longer means exclusive mode.
 *
 * @param int $courseid
 * @return string 'eds' when EDS path exists, otherwise 'scan'
 */
function local_sentaldocupload_get_course_mode(int $courseid): string {
    return local_sentaldocupload_course_has_eds_template_profile($courseid) ? 'eds' : 'scan';
}

/**
 * Human-readable upload path label.
 *
 * @param int $courseid
 * @return string
 */
function local_sentaldocupload_get_course_mode_label(int $courseid): string {
    return local_sentaldocupload_course_has_eds_template_profile($courseid)
        ? get_string('uploadpaths_eds_manual', 'local_sentaldocupload')
        : get_string('uploadpaths_manual', 'local_sentaldocupload');
}

/**
 * Legacy method retained for older course-mode page calls. The confirmed workflow
 * removed course-level modes, so this should not be used by the new UI.
 *
 * @param int $courseid
 * @param string $mode
 * @return bool
 */
function local_sentaldocupload_set_course_mode(int $courseid, string $mode): bool {
    global $DB, $USER;

    $table = local_sentaldocupload_ncasign_table();
    if (!$DB->get_manager()->table_exists($table)) {
        return false;
    }

    if ($mode === 'scan') {
        $DB->delete_records($table, ['courseid' => $courseid]);
        return true;
    }

    if (!$DB->record_exists($table, ['courseid' => $courseid])) {
        $columns = $DB->get_columns($table);
        $now = time();
        $record = (object)['courseid' => $courseid];
        foreach (['templateid' => 0, 'profileid' => 0, 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $USER->id ?? 0] as $field => $value) {
            if (isset($columns[$field])) {
                $record->$field = $value;
            }
        }
        $DB->insert_record($table, $record);
    }
    return true;
}

/**
 * Get validity period in days from a course custom field.
 * The custom field shortname is configured in plugin settings and defaults to validity_period.
 *
 * @param int $courseid
 * @return int 0 means no expiry/not configured.
 */
function local_sentaldocupload_get_course_validity_days(int $courseid): int {
    global $DB;

    $shortname = get_config('local_sentaldocupload', 'validityfieldshortname') ?: 'validity_period';
    $columns = $DB->get_columns('customfield_data');
    $valuefields = array_values(array_filter(
        ['intvalue', 'decvalue', 'shortcharvalue', 'charvalue', 'value'],
        static function($field) use ($columns) {
            return isset($columns[$field]);
        }
    ));

    if (!$valuefields) {
        return 0;
    }

    $selectfields = implode(', ', array_map(static function($field) {
        return 'd.' . $field;
    }, $valuefields));

    $sql = "SELECT d.id, $selectfields
              FROM {customfield_data} d
              JOIN {customfield_field} f ON f.id = d.fieldid
             WHERE f.shortname = :shortname
               AND d.instanceid = :courseid";
    $record = $DB->get_record_sql($sql, ['shortname' => $shortname, 'courseid' => $courseid], IGNORE_MULTIPLE);

    if (!$record) {
        return 0;
    }

    foreach ($valuefields as $field) {
        if (isset($record->$field) && $record->$field !== '' && $record->$field !== null) {
            return max(0, (int)$record->$field);
        }
    }

    return 0;
}

/**
 * Calculate expiry timestamp from issue date and validity days.
 *
 * @param int $issuedate
 * @param int $validitydays
 * @return int|null null means no expiry.
 */
function local_sentaldocupload_calculate_expiry(int $issuedate, int $validitydays): ?int {
    if ($validitydays <= 0) {
        return null;
    }
    return strtotime('+' . $validitydays . ' days', $issuedate) ?: null;
}

/**
 * Dynamic certification status. Status is never stored in DB.
 *
 * @param int|null $expirydate
 * @param bool $hasdocument
 * @return string active|expiring|expired|nodocument
 */
function local_sentaldocupload_get_status(?int $expirydate, bool $hasdocument = true): string {
    if (!$hasdocument) {
        return 'nodocument';
    }
    if (empty($expirydate)) {
        return 'active';
    }
    $now = time();
    if ($expirydate < $now) {
        return 'expired';
    }
    if ($expirydate <= strtotime('+30 days', $now)) {
        return 'expiring';
    }
    return 'active';
}

/**
 * Render a status badge.
 *
 * @param string $status
 * @return string
 */
function local_sentaldocupload_status_badge(string $status): string {
    $map = [
        'active' => ['text' => get_string('statusactive', 'local_sentaldocupload'), 'class' => 'success'],
        'expiring' => ['text' => get_string('statusexpiring', 'local_sentaldocupload'), 'class' => 'warning'],
        'expired' => ['text' => get_string('statusexpired', 'local_sentaldocupload'), 'class' => 'danger'],
        'nodocument' => ['text' => get_string('statusnodocument', 'local_sentaldocupload'), 'class' => 'secondary'],
    ];
    $item = $map[$status] ?? $map['nodocument'];
    return html_writer::span(s($item['text']), 'badge badge-' . $item['class']);
}

/**
 * Check whether IOMAD company tables are available.
 *
 * @return bool
 */
function local_sentaldocupload_iomad_tables_exist(): bool {
    global $DB;
    $manager = $DB->get_manager();
    return $manager->table_exists('company') && $manager->table_exists('company_users');
}

/**
 * Best-effort current IOMAD company resolver.
 *
 * @return int
 */
function local_sentaldocupload_get_current_company_id(): int {
    global $DB, $SESSION;

    if (!local_sentaldocupload_iomad_tables_exist()) {
        return 0;
    }

    $context = context_system::instance();
    $sessionkeys = [
        'currenteditingcompany', 'companyid', 'currentcompanyid', 'current_companyid',
        'currentcompany', 'iomad_companyid', 'selectedcompanyid', 'currenteditingcompanyid',
    ];

    foreach ($sessionkeys as $key) {
        if (!isset($SESSION->$key)) {
            continue;
        }
        $value = $SESSION->$key;
        $candidate = 0;
        if (is_scalar($value)) {
            $candidate = (int)$value;
        } else if (is_object($value) && !empty($value->id)) {
            $candidate = (int)$value->id;
        } else if (is_array($value) && !empty($value['id'])) {
            $candidate = (int)$value['id'];
        }
        if ($candidate > 0 && $DB->record_exists('company', ['id' => $candidate])) {
            return $candidate;
        }
    }

    $iomadlib = __DIR__ . '/../iomad/lib/iomad.php';
    if (file_exists($iomadlib)) {
        require_once($iomadlib);
        if (class_exists('iomad') && method_exists('iomad', 'get_my_companyid')) {
            try {
                $candidate = \iomad::get_my_companyid($context, false);
                if (is_object($candidate) && !empty($candidate->id)) {
                    $candidate = (int)$candidate->id;
                } else {
                    $candidate = (int)$candidate;
                }
                if ($candidate > 0 && $DB->record_exists('company', ['id' => $candidate])) {
                    return $candidate;
                }
            } catch (Throwable $e) {
                // Ignore and fall back below.
            }
        }
    }

    $visible = local_sentaldocupload_get_visible_companies();
    if (count($visible) === 1) {
        return (int)array_key_first($visible);
    }

    return 0;
}

/**
 * Return companies visible to the current manager/admin.
 *
 * @return array companyid => company name
 */
function local_sentaldocupload_get_visible_companies(): array {
    global $DB, $USER;

    if (!local_sentaldocupload_iomad_tables_exist()) {
        return [];
    }

    if (is_siteadmin()) {
        return $DB->get_records_menu('company', null, 'name ASC', 'id, name');
    }

    $sql = "SELECT c.id, c.name
              FROM {company} c
              JOIN {company_users} cu ON cu.companyid = c.id
             WHERE cu.userid = :userid
          ORDER BY c.name ASC";
    return $DB->get_records_sql_menu($sql, ['userid' => $USER->id]);
}

/**
 * Return company IDs linked with one user.
 *
 * @param int $userid
 * @return array
 */
function local_sentaldocupload_get_user_company_ids(int $userid): array {
    global $DB;
    if (!local_sentaldocupload_iomad_tables_exist()) {
        return [];
    }
    $records = $DB->get_records('company_users', ['userid' => $userid], '', 'id, companyid');
    return array_values(array_unique(array_map(static function($record) {
        return (int)$record->companyid;
    }, $records)));
}

/**
 * Check if a selected learner belongs to a selected company.
 *
 * @param int $userid
 * @param int $companyid
 * @return bool
 */
function local_sentaldocupload_user_in_company(int $userid, int $companyid): bool {
    global $DB;
    if ($companyid <= 0 || !local_sentaldocupload_iomad_tables_exist()) {
        return true;
    }
    return $DB->record_exists('company_users', ['userid' => $userid, 'companyid' => $companyid]);
}

/**
 * Get active upload users, optionally filtered by company.
 *
 * @param int $companyid
 * @return array userid => user object
 */
function local_sentaldocupload_get_upload_users(int $companyid = 0): array {
    global $DB;
    $params = [];
    if (local_sentaldocupload_iomad_tables_exist()) {
        $join = '';
        $wherecompany = '';
        if ($companyid > 0) {
            $join = 'JOIN {company_users} cu_filter ON cu_filter.userid = u.id';
            $wherecompany = 'AND cu_filter.companyid = :companyid';
            $params['companyid'] = $companyid;
        }
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                  $join
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
                   $wherecompany
              ORDER BY u.lastname ASC, u.firstname ASC";
    } else {
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
              ORDER BY u.lastname ASC, u.firstname ASC";
    }
    $users = $DB->get_records_sql($sql, $params, 0, 2000);
    foreach ($users as $user) {
        $user->fullname = fullname($user);
        $user->companyids = implode(',', local_sentaldocupload_get_user_company_ids((int)$user->id));
    }
    return $users;
}

/**
 * Get all courses for a selected user. Manual scan upload is available for every course.
 *
 * @param int $userid
 * @return array courseid => course object
 */
function local_sentaldocupload_get_user_courses(int $userid): array {
    require_once($GLOBALS['CFG']->libdir . '/enrollib.php');
    $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname, visible');
    core_collator::asort_objects_by_property($courses, 'fullname');
    return $courses;
}

/**
 * Legacy alias. Do not filter by Mode B anymore.
 *
 * @param int $userid
 * @return array
 */
function local_sentaldocupload_get_user_modeb_courses(int $userid): array {
    return local_sentaldocupload_get_user_courses($userid);
}

/**
 * Get users enrolled in a course for group upload.
 *
 * @param int $courseid
 * @param int $excludeuserid
 * @param int $companyid
 * @return array userid => user object
 */
function local_sentaldocupload_get_course_participants(int $courseid, int $excludeuserid = 0, int $companyid = 0): array {
    global $DB;
    $params = ['courseid' => $courseid, 'excludeuserid' => $excludeuserid];
    $companyjoin = '';
    $companywhere = '';

    if ($companyid > 0 && local_sentaldocupload_iomad_tables_exist()) {
        $companyjoin = 'JOIN {company_users} cu ON cu.userid = u.id';
        $companywhere = 'AND cu.companyid = :companyid';
        $params['companyid'] = $companyid;
    }

    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
              FROM {user} u
              JOIN {user_enrolments} ue ON ue.userid = u.id
              JOIN {enrol} e ON e.id = ue.enrolid
              $companyjoin
             WHERE e.courseid = :courseid
               AND u.deleted = 0
               AND u.suspended = 0
               AND u.id > 1
               AND u.id <> :excludeuserid
               $companywhere
          ORDER BY u.lastname ASC, u.firstname ASC";
    $users = $DB->get_records_sql($sql, $params, 0, 2000);
    foreach ($users as $user) {
        $user->fullname = fullname($user);
        $user->companyids = implode(',', local_sentaldocupload_get_user_company_ids((int)$user->id));
    }
    return $users;
}

/**
 * Check whether a user is enrolled in a course.
 *
 * @param int $userid
 * @param int $courseid
 * @return bool
 */
function local_sentaldocupload_user_enrolled_in_course(int $userid, int $courseid): bool {
    global $DB;
    $sql = "SELECT 1
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
             WHERE ue.userid = :userid
               AND e.courseid = :courseid";
    return $DB->record_exists_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
}


/**
 * Return the next safe version number for a document.
 *
 * @param int $documentid
 * @return int
 */
function local_sentaldocupload_get_next_version_number(int $documentid): int {
    global $DB;
    $max = $DB->get_field_sql(
        'SELECT MAX(versionno) FROM {sental_modeb_doc_version} WHERE documentid = :documentid',
        ['documentid' => $documentid]
    );
    return ((int)$max) + 1;
}

/**
 * Link a document to a learner if that link does not already exist.
 *
 * @param int $documentid
 * @param int $userid
 * @param int $timecreated
 */
function local_sentaldocupload_link_document_user(int $documentid, int $userid, int $timecreated, int $showinpublicprofile = 0, int $autocompleted = 0, int $completiontime = 0, int $publicprofileoverride = 0): void {
    global $DB;

    $columns = $DB->get_columns('sental_modeb_doc_user');
    $existing = $DB->get_record('sental_modeb_doc_user', ['documentid' => $documentid, 'userid' => $userid]);

    if ($existing) {
        $updaterecord = (object)['id' => $existing->id];
        if (isset($columns['showinpublicprofile'])) {
            $updaterecord->showinpublicprofile = $showinpublicprofile;
        }
        if (isset($columns['publicprofileoverride'])) {
            $updaterecord->publicprofileoverride = $publicprofileoverride;
        }
        if (isset($columns['autocompleted'])) {
            $updaterecord->autocompleted = max((int)($existing->autocompleted ?? 0), $autocompleted);
        }
        if (isset($columns['completiontime']) && $completiontime > 0) {
            $updaterecord->completiontime = $completiontime;
        }
        if (count((array)$updaterecord) > 1) {
            $DB->update_record('sental_modeb_doc_user', $updaterecord);
        }
        return;
    }

    $record = (object)[
        'documentid' => $documentid,
        'userid' => $userid,
        'timecreated' => $timecreated,
    ];
    if (isset($columns['showinpublicprofile'])) {
        $record->showinpublicprofile = $showinpublicprofile;
    }
    if (isset($columns['publicprofileoverride'])) {
        $record->publicprofileoverride = $publicprofileoverride;
    }
    if (isset($columns['autocompleted'])) {
        $record->autocompleted = $autocompleted;
    }
    if (isset($columns['completiontime'])) {
        $record->completiontime = $completiontime;
    }
    $DB->insert_record('sental_modeb_doc_user', $record);
}

/**
 * Check whether learner has completed the course.
 *
 * @param int $courseid
 * @param int $userid
 * @return bool
 */
function local_sentaldocupload_is_course_completed(int $courseid, int $userid): bool {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');
    $course = get_course($courseid);
    $completion = new completion_info($course);
    if (!$completion->is_enabled()) {
        return false;
    }
    return $completion->is_course_complete($userid);
}

/**
 * Ask NCASign to ignore the course_completed event caused by this manual upload.
 *
 * @param int $courseid
 * @param int $userid
 * @param int $sourceid uploaded document version id
 * @param int $timecreated
 * @return void
 */
function local_sentaldocupload_create_ncasign_completion_suppression(
    int $courseid,
    int $userid,
    int $sourceid = 0,
    int $timecreated = 0
): void {
    global $DB;

    $table = 'local_ncasign_completion_suppress';
    if (!$DB->get_manager()->table_exists($table)) {
        return;
    }

    $timecreated = $timecreated ?: time();
    $record = (object)[
        'userid' => $userid,
        'courseid' => $courseid,
        'sourcecomponent' => 'local_sentaldocupload',
        'sourceid' => $sourceid,
        'reason' => 'manual_course_completion_upload',
        'consumed' => 0,
        'timecreated' => $timecreated,
        'expiresat' => $timecreated + 600,
        'timeconsumed' => 0,
    ];

    try {
        $DB->insert_record($table, $record);
    } catch (Throwable $e) {
        debugging('Unable to create NCASign completion suppression: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Mark course complete for a learner when a Type 1 course completion document is uploaded.
 *
 * @param int $courseid
 * @param int $userid
 * @param int|null $timecompleted
 * @param int $sourceid uploaded document version id
 * @return bool true when this call completed the course, false when already completed
 */
function local_sentaldocupload_mark_course_completed(
    int $courseid,
    int $userid,
    ?int $timecompleted = null,
    int $sourceid = 0
): bool {
    global $DB, $CFG;
    require_once($CFG->libdir . '/completionlib.php');
    require_once($CFG->dirroot . '/completion/completion_completion.php');

    $timecompleted = $timecompleted ?: time();
    $course = get_course($courseid);

    // The client requirement says Type 1 upload must complete the learner's course.
    // Some courses may not have Moodle completion tracking enabled yet, so enable it
    // before marking the completion record.
    if (isset($course->enablecompletion) && (int)$course->enablecompletion !== COMPLETION_ENABLED) {
        $DB->set_field('course', 'enablecompletion', COMPLETION_ENABLED, ['id' => $courseid]);
        rebuild_course_cache($courseid, true);
        $course = get_course($courseid);
    }

    $params = ['course' => $courseid, 'userid' => $userid];
    $existing = $DB->get_record('course_completions', $params);
    $alreadycompleted = ($existing && !empty($existing->timecompleted));

    if (!$alreadycompleted) {
        local_sentaldocupload_create_ncasign_completion_suppression($courseid, $userid, $sourceid, $timecompleted);

        try {
            $ccompletion = new completion_completion(['userid' => $userid, 'course' => $courseid]);
            $ccompletion->mark_complete($timecompleted);
        } catch (Throwable $e) {
            // Fallback below will still write the completion row. Keep this as debug only.
            debugging('SENTAL manual upload completion API fallback used: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Verify/enforce the completion row. This is the value used by Moodle's course completion reports.
        $existing = $DB->get_record('course_completions', $params);
        if ($existing) {
            if (empty($existing->timecompleted)) {
                $existing->timecompleted = $timecompleted;
                $existing->reaggregate = 0;
                if (empty($existing->timestarted)) {
                    $existing->timestarted = $timecompleted;
                }
                $DB->update_record('course_completions', $existing);
            }
        } else {
            $DB->insert_record('course_completions', (object)[
                'userid' => $userid,
                'course' => $courseid,
                'timeenrolled' => 0,
                'timestarted' => $timecompleted,
                'timecompleted' => $timecompleted,
                'reaggregate' => 0,
            ]);
        }
    }

    // Many Moodle themes/course overview blocks show the progress bar from activity completion
    // ({course_modules_completion}), not only from {course_completions}.
    // Therefore Type 1 upload must also complete all completion-tracked activities for this learner.
    local_sentaldocupload_force_activity_progress_complete($courseid, $userid, $timecompleted);

    return !$alreadycompleted;
}

/**
 * Force all completion-tracked course activities complete for a learner.
 *
 * This makes dashboard/course progress bars reach 100% in themes that calculate progress
 * from activity completion rather than the course_completions table.
 *
 * @param int $courseid
 * @param int $userid
 * @param int $timecompleted
 * @return void
 */
function local_sentaldocupload_force_activity_progress_complete(int $courseid, int $userid, int $timecompleted): void {
    global $DB, $USER;

    $cms = $DB->get_records_select(
        'course_modules',
        'course = :courseid AND deletioninprogress = 0 AND completion <> 0',
        ['courseid' => $courseid],
        '',
        'id, course, completion'
    );

    if (!$cms) {
        // No completion-tracked activities exist. In that case Moodle/theme progress bars may have
        // nothing to count, even though the course itself is completed.
        return;
    }

    foreach ($cms as $cm) {
        $params = ['coursemoduleid' => $cm->id, 'userid' => $userid];
        $record = $DB->get_record('course_modules_completion', $params);

        if ($record) {
            if ((int)$record->completionstate !== COMPLETION_COMPLETE) {
                $record->completionstate = COMPLETION_COMPLETE;
                $record->timemodified = $timecompleted;
                if ($DB->get_manager()->field_exists('course_modules_completion', 'overrideby')) {
                    $record->overrideby = $USER->id ?? 0;
                }
                $DB->update_record('course_modules_completion', $record);
            }
        } else {
            $newrecord = (object)[
                'coursemoduleid' => $cm->id,
                'userid' => $userid,
                'completionstate' => COMPLETION_COMPLETE,
                'viewed' => 1,
                'timemodified' => $timecompleted,
            ];
            if ($DB->get_manager()->field_exists('course_modules_completion', 'overrideby')) {
                $newrecord->overrideby = $USER->id ?? 0;
            }
            $DB->insert_record('course_modules_completion', $newrecord);
        }
    }

    // Clear course cache so progress calculations use the latest completion data.
    rebuild_course_cache($courseid, true);
}


/**
 * Return Type 1 manual scans that are allowed for a learner's Public Profile.
 * Type 2 supplementary documents are intentionally excluded.
 *
 * Public Profile visibility is separate from the student's private My Certifications page.
 * Only the current Type 1 version is displayed, and only when Show in Public Profile is checked.
 *
 * @param int $userid
 * @param int|null $courseid optional course filter
 * @return array
 */
function local_sentaldocupload_get_public_profile_scans(int $userid, ?int $courseid = null): array {
    global $DB;

    $params = ['userid' => $userid, 'doctype' => 'type1'];
    $coursewhere = '';
    if (!empty($courseid)) {
        $coursewhere = 'AND d.courseid = :courseid';
        $params['courseid'] = $courseid;
    }

    $ducolumns = $DB->get_columns('sental_modeb_doc_user');
    $versioncolumns = $DB->get_columns('sental_modeb_doc_version');

    $useroverrideexpr = isset($ducolumns['publicprofileoverride'])
        ? 'COALESCE(du.publicprofileoverride, 0)'
        : '0';

    $versionoverrideexpr = isset($versioncolumns['publicprofileoverride'])
        ? 'COALESCE(v.publicprofileoverride, 0)'
        : '0';

    // v.id is the first field so get_records_sql() keys by version, not document.
    // Public profile shows Type 1 only. It shows the latest checked-public version
    // for each learner/course/document. It does NOT show Type 2.
    $sql = "SELECT v.id AS id,
                   d.id AS documentid,
                   d.courseid,
                   c.fullname AS coursefullname,
                   c.shortname AS courseshortname,
                   d.documenttype,
                   d.currentversion,
                   COALESCE(d.showinpublicprofile, 0) AS doc_showinpublicprofile,
                   du.userid,
                   COALESCE(du.showinpublicprofile, 0) AS user_showinpublicprofile,
                   $useroverrideexpr AS user_publicprofileoverride,
                   v.id AS versionid,
                   v.versionno,
                   v.filename,
                   v.issuedate,
                   v.expirydate,
                   COALESCE(v.showinpublicprofile, 0) AS version_showinpublicprofile,
                   $versionoverrideexpr AS version_publicprofileoverride,
                   v.timecreated
              FROM {sental_modeb_doc} d
              JOIN {sental_modeb_doc_user} du ON du.documentid = d.id
              JOIN {course} c ON c.id = d.courseid
              JOIN {sental_modeb_doc_version} v ON v.documentid = d.id
             WHERE du.userid = :userid
               AND d.documenttype = :doctype
               $coursewhere
          ORDER BY c.fullname ASC, d.id DESC, v.versionno DESC, v.timecreated DESC";

    $records = $DB->get_records_sql($sql, $params);
    $visible = [];
    $seen = [];

    foreach ($records as $record) {
        // A scan is public when the uploaded Type 1 version is checked for public profile.
        // For older records, accept document/user-level public flags as fallback because
        // some previous plugin versions saved the checkbox at those levels only.
        $ispublic = !empty($record->version_showinpublicprofile)
            || !empty($record->version_publicprofileoverride)
            || !empty($record->user_showinpublicprofile)
            || !empty($record->user_publicprofileoverride)
            || !empty($record->doc_showinpublicprofile);

        if (!$ispublic) {
            continue;
        }

        // If EDS exists, a manual scan is still hidden unless an admin explicitly
        // checked Show in Public Profile. Any public flag above is treated as that
        // explicit public choice for backwards compatibility.
        $hasedsdocument = local_sentaldocupload_user_course_has_eds_document((int)$record->courseid, $userid);
        if ($hasedsdocument && !$ispublic) {
            continue;
        }

        // Keep only the latest visible public version for each document.
        $documentkey = (int)$record->documentid;
        if (isset($seen[$documentkey])) {
            continue;
        }
        $seen[$documentkey] = true;

        $record->status = local_sentaldocupload_get_status(empty($record->expirydate) ? null : (int)$record->expirydate, true);
        $record->statushtml = local_sentaldocupload_status_badge($record->status);
        $record->publicurl = (new moodle_url('/local/sentaldocupload/publicfile.php', [
            'versionid' => (int)$record->versionid,
            'userid' => $userid,
            'courseid' => (int)$record->courseid,
        ]))->out(false);
        $record->viewerurl = (new moodle_url('/local/sentaldocupload/viewer.php', [
            'versionid' => (int)$record->versionid,
            'userid' => $userid,
            'courseid' => (int)$record->courseid,
            'public' => 1,
        ]))->out(false);
        $visible[(int)$record->versionid] = $record;
    }

    return $visible;
}

/**
 * Render the public profile Certification cards.
 *
 * @param int $userid
 * @return string
 */
function local_sentaldocupload_render_public_profile_certifications(int $userid): string {
    $scans = local_sentaldocupload_get_public_profile_scans($userid);
    if (empty($scans)) {
        return '';
    }

    $formatdate = static function($timestamp) {
        return empty($timestamp) ? get_string('noexpiry', 'local_sentaldocupload') : userdate((int)$timestamp, get_string('strftimedate', 'langconfig'));
    };

    $out = html_writer::start_div('sental-public-profile-certifications');
    foreach ($scans as $scan) {
        $status = local_sentaldocupload_get_status(empty($scan->expirydate) ? null : (int)$scan->expirydate, true);
        $out .= html_writer::start_div('sental-public-profile-cert-card status-' . $status);
        $out .= html_writer::div(
            html_writer::span('📄', 'sental-public-profile-cert-icon') . local_sentaldocupload_status_badge($status),
            'sental-public-profile-cert-top'
        );
        $out .= html_writer::tag('strong', format_string($scan->coursefullname), ['class' => 'sental-public-profile-cert-course']);
        $out .= html_writer::div(s($scan->filename), 'sental-public-profile-cert-file');
        $out .= html_writer::div(get_string('versionno', 'local_sentaldocupload') . ': v' . (int)$scan->versionno, 'sental-public-profile-cert-meta');
        $out .= html_writer::div(get_string('validationdate', 'local_sentaldocupload') . ': ' . s($formatdate($scan->issuedate)), 'sental-public-profile-cert-meta');
        $out .= html_writer::div(get_string('expirydate', 'local_sentaldocupload') . ': ' . s($formatdate($scan->expirydate)), 'sental-public-profile-cert-meta');
        $out .= html_writer::link($scan->viewerurl, get_string('viewdocument', 'local_sentaldocupload'), ['class' => 'btn btn-sm btn-outline-success sental-public-profile-cert-download']);
        $out .= html_writer::end_div();
    }
    $out .= html_writer::end_div();
    return $out;
}

/**
 * Add public certifications to the Moodle user profile page.
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_sentaldocupload_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    // Disabled by requirement: do not show SENTAL certifications on Moodle public profile.
    // Course completion Type 1 documents are still stored in the plugin tables and Moodle File API.
    // Their display location will be handled separately later.
    return;
}

/**
 * Return public-profile display state for one learner-course pair.
 * This helper is intended for integration with the SENTAL public profile page.
 *
 * @param int $userid
 * @param int $courseid
 * @return array{source:string, scans:array, hasedsdocument:bool}
 */
function local_sentaldocupload_get_public_profile_display_state(int $userid, int $courseid): array {
    $hasedsdocument = local_sentaldocupload_user_course_has_eds_document($courseid, $userid);
    $scans = local_sentaldocupload_get_public_profile_scans($userid, $courseid);

    if ($hasedsdocument) {
        return ['source' => 'eds', 'scans' => $scans, 'hasedsdocument' => true];
    }
    if (!empty($scans)) {
        return ['source' => 'type1scan', 'scans' => $scans, 'hasedsdocument' => false];
    }
    return ['source' => 'none', 'scans' => [], 'hasedsdocument' => false];
}

/**
 * Write immutable audit entry.
 *
 * @param int $documentid
 * @param int|null $versionid
 * @param int|null $userid linked learner, if known
 * @param string $actiontype
 */
function local_sentaldocupload_audit(int $documentid, ?int $versionid, ?int $userid, string $actiontype): void {
    global $DB, $USER;

    // Append-only audit logging. The plugin never updates or deletes rows from this table.
    // This records: timestamp, actor, IP address, document, version, linked learner, and action type.
    $DB->insert_record('sental_modeb_audit', (object)[
        'documentid' => $documentid,
        'versionid' => $versionid,
        'userid' => $userid,
        'actorid' => $USER->id,
        'ipaddress' => getremoteaddr(),
        'actiontype' => clean_param($actiontype, PARAM_ALPHANUMEXT),
        'timecreated' => time(),
    ]);
}

/**
 * Human-readable audit action labels.
 *
 * @param string $actiontype
 * @return string
 */
function local_sentaldocupload_get_audit_action_label(string $actiontype): string {
    $map = [
        'upload' => get_string('audit_action_upload', 'local_sentaldocupload'),
        'replace' => get_string('audit_action_replace', 'local_sentaldocupload'),
        'view' => get_string('audit_action_view', 'local_sentaldocupload'),
        'download' => get_string('audit_action_download', 'local_sentaldocupload'),
        'course_completed' => get_string('audit_action_course_completed', 'local_sentaldocupload'),
        'public_view' => get_string('audit_action_public_view', 'local_sentaldocupload'),
    ];
    return $map[$actiontype] ?? s($actiontype);
}

/**
 * Serve uploaded document file from Moodle File API.
 *
 * @param stored_file $file
 * @param bool $forcedownload
 */
function local_sentaldocupload_send_file(stored_file $file, bool $forcedownload = true): void {
    send_stored_file($file, 0, 0, $forcedownload, ['filename' => $file->get_filename()]);
}


/**
 * Can the current user upload SENTAL documents for a course?
 *
 * Global managers use local/sentaldocupload:manage. Course teachers/editing teachers
 * can upload only from the course page for their own course by using standard
 * Moodle course-management capabilities.
 *
 * @param int $courseid
 * @return bool
 */
function local_sentaldocupload_can_upload_for_course(int $courseid): bool {
    if (!isloggedin() || isguestuser()) {
        return false;
    }

    if (has_capability('local/sentaldocupload:manage', context_system::instance())) {
        return true;
    }

    if ($courseid <= 0) {
        return false;
    }

    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context) {
        return false;
    }

    return has_capability('moodle/course:update', $context) || has_capability('moodle/course:manageactivities', $context);
}

/**
 * Require upload permission for a selected course.
 *
 * @param int $courseid
 */
function local_sentaldocupload_require_upload_for_course(int $courseid): void {
    if (!local_sentaldocupload_can_upload_for_course($courseid)) {
        throw new required_capability_exception(
            $courseid > 0 ? context_course::instance($courseid, IGNORE_MISSING) ?: context_system::instance() : context_system::instance(),
            'local/sentaldocupload:manage',
            'nopermissions',
            ''
        );
    }
}

/**
 * Check if the current page is a real Moodle course homepage.
 *
 * @return bool
 */
function local_sentaldocupload_is_course_homepage(): bool {
    global $PAGE, $COURSE, $SITE;
    if (!isloggedin() || isguestuser()) {
        return false;
    }
    if (empty($COURSE) || empty($COURSE->id) || (int)$COURSE->id === (int)$SITE->id) {
        return false;
    }
    $path = $PAGE->url ? $PAGE->url->get_path() : '';
    return strpos($path, '/course/view.php') !== false;
}


/**
 * Check if the current page is Moodle's My courses page.
 *
 * @return bool
 */
function local_sentaldocupload_is_my_courses_page(): bool {
    global $PAGE;
    if (!isloggedin() || isguestuser()) {
        return false;
    }
    $path = $PAGE->url ? $PAGE->url->get_path() : '';
    return $path === '/my/courses.php' || strpos($path, '/my/courses.php') !== false;
}

/**
 * Add body classes early so upload-path badge can be rendered by CSS, not JavaScript.
 */
function local_sentaldocupload_before_http_headers(): void {
    global $PAGE, $COURSE;
    if (!local_sentaldocupload_is_course_homepage()) {
        return;
    }
    $path = local_sentaldocupload_course_has_eds_template_profile((int)$COURSE->id) ? 'edsmanual' : 'manual';
    $PAGE->add_body_class('sental-upload-path-enabled');
    $PAGE->add_body_class('sental-upload-path-' . $path);
}

/**
 * Render upload-path badge through CSS before the course title. No AJAX/JS is used.
 *
 * @return string
 */
function local_sentaldocupload_before_standard_html_head(): string {
    global $COURSE;
    if (!local_sentaldocupload_is_course_homepage()) {
        return '';
    }

    $label = local_sentaldocupload_get_course_mode_label((int)$COURSE->id);
    $csslabel = json_encode($label);
    $haseds = local_sentaldocupload_course_has_eds_template_profile((int)$COURSE->id);

    $bg = $haseds ? '#e7f1ff' : '#e6f7ee';
    $color = $haseds ? '#084298' : '#006b3c';
    $border = $haseds ? '#9ec5fe' : '#9bd8b9';

    return html_writer::tag('style', "
body.sental-upload-path-enabled.path-course-view .page-header-headings h1::before,
body.sental-upload-path-enabled.path-course-view #page-header h1::before,
body.sental-upload-path-enabled.path-course-view .course-header h1::before,
body.sental-upload-path-enabled.path-course-view .course-info-container h1::before,
body.sental-upload-path-enabled.path-course-view .page-context-header h1::before,
body.sental-upload-path-enabled.path-course-view .header-main-content h1::before {
    content: {$csslabel};
    display: table;
    width: auto;
    max-width: max-content;
    margin: 0 0 12px 0;
    padding: 7px 18px;
    border-radius: 999px;
    border: 1px solid {$border};
    background: {$bg};
    color: {$color};
    font-size: 15px;
    font-weight: 700;
    line-height: 1.35;
    letter-spacing: 0;
    text-transform: none;
}
");
}

/**
 * Should the current user see the student certifications button on a course page?
 *
 * @param int $courseid
 * @return bool
 */
function local_sentaldocupload_show_student_course_button(int $courseid): bool {
    global $USER;

    if (!isloggedin() || isguestuser() || $courseid <= 0 || is_siteadmin()) {
        return false;
    }

    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context) {
        return false;
    }

    // Show this only to enrolled learners, not teachers/managers/admins.
    if (!is_enrolled($context, $USER, '', true)) {
        return false;
    }
    if (has_capability('local/sentaldocupload:manage', context_system::instance())) {
        return false;
    }
    if (has_capability('moodle/course:manageactivities', $context) || has_capability('moodle/course:update', $context)) {
        return false;
    }

    return true;
}


/**
 * Should the current user see My certifications on the My courses page?
 *
 * @return bool
 */
function local_sentaldocupload_show_student_my_courses_button(): bool {
    global $USER;

    if (!isloggedin() || isguestuser() || is_siteadmin()) {
        return false;
    }
    if (has_capability('local/sentaldocupload:manage', context_system::instance())) {
        return false;
    }

    require_once($GLOBALS['CFG']->libdir . '/enrollib.php');
    $courses = enrol_get_users_courses($USER->id, true, 'id');
    if (empty($courses)) {
        return false;
    }

    // Show when at least one enrolled course is a learner course for this user.
    foreach ($courses as $course) {
        $context = context_course::instance((int)$course->id, IGNORE_MISSING);
        if (!$context) {
            continue;
        }
        if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/course:manageactivities', $context)) {
            return true;
        }
    }

    return false;
}

/**
 * Can the current user upload SENTAL documents for at least one course?
 *
 * This is used on /my/courses.php where no single course id is available.
 *
 * @return bool
 */
function local_sentaldocupload_user_can_upload_any_course(): bool {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return false;
    }

    if (has_capability('local/sentaldocupload:manage', context_system::instance())) {
        return true;
    }

    require_once($GLOBALS['CFG']->libdir . '/enrollib.php');
    $courses = enrol_get_users_courses($USER->id, true, 'id');
    foreach ($courses as $course) {
        $context = context_course::instance((int)$course->id, IGNORE_MISSING);
        if (!$context) {
            continue;
        }
        if (has_capability('moodle/course:update', $context) || has_capability('moodle/course:manageactivities', $context)) {
            return true;
        }
    }

    return false;
}

/**
 * Render SENTAL page buttons.
 *
 * Students see My certifications. Admins/teachers/managers see SENTAL document upload.
 */
function local_sentaldocupload_before_footer() {
    global $COURSE;

    // My courses page: place the button in the heading/action area.
    if (local_sentaldocupload_is_my_courses_page()) {
        $buttonhtml = '';
        $wrapclass = 'sental-my-courses-action-btn-wrap';

        if (local_sentaldocupload_user_can_upload_any_course()) {
            $url = new moodle_url('/local/sentaldocupload/index.php');
            $label = get_string('sentaldocumentuploadcoursebutton', 'local_sentaldocupload');
            $buttonhtml = html_writer::link($url, $label, [
                'class' => 'sental-my-courses-action-btn sental-my-courses-upload-btn'
            ]);
            $wrapclass .= ' sental-my-courses-upload-btn-wrap';
        } else if (local_sentaldocupload_show_student_my_courses_button()) {
            $url = new moodle_url('/local/sentaldocupload/mydocuments.php');
            $label = get_string('mycertificationscoursebutton', 'local_sentaldocupload');
            $buttonhtml = html_writer::link($url, $label, [
                'class' => 'sental-my-courses-action-btn sental-my-courses-certifications-btn'
            ]);
            $wrapclass .= ' sental-my-courses-certifications-btn-wrap';
        }

        if ($buttonhtml !== '') {
            echo html_writer::div(
                $buttonhtml,
                $wrapclass,
                ['id' => 'sental-my-courses-action-wrap']
            );
            echo html_writer::script(<<<'JS'
(function() {
    var attempts = 0;

    function textOf(el) {
        return (el && (el.innerText || el.textContent) || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function findButtonByText(labels) {
        var els = Array.prototype.slice.call(document.querySelectorAll('a, button, input[type="submit"], input[type="button"]'));
        for (var i = 0; i < els.length; i++) {
            var text = textOf(els[i]);
            var value = (els[i].value || '').toLowerCase();
            for (var j = 0; j < labels.length; j++) {
                if (text.indexOf(labels[j]) !== -1 || value.indexOf(labels[j]) !== -1) {
                    return els[i];
                }
            }
        }
        return null;
    }

    function commonAncestor(a, b) {
        if (!a || !b) {
            return null;
        }
        var p = a;
        while (p && p !== document.body) {
            if (p.contains(b)) {
                return p;
            }
            p = p.parentElement;
        }
        return null;
    }

    function findAdminActionGroup() {
        var manage = findButtonByText(['manage courses']);
        var create = findButtonByText(['create course']);
        var ref = manage || create;
        if (!ref) {
            return null;
        }

        var group = commonAncestor(manage, create);
        if (group && group !== document.body) {
            while (group.parentElement && group.parentElement !== document.body) {
                var r = group.getBoundingClientRect ? group.getBoundingClientRect() : {width: 0};
                if (r.width > 120 && r.width < window.innerWidth) {
                    break;
                }
                group = group.parentElement;
            }
            return group;
        }

        return ref.closest('.page-header-actions, .header-actions, .header-actions-container, .d-flex, .d-inline-flex, .btn-group, .singlebutton') || ref.parentElement;
    }

    function findHeading() {
        var selectors = [
            'body#page-my-courses [role="main"] h1',
            'body#page-my-courses #page-content h1',
            'body#page-my-courses #region-main h1',
            'body#page-my-courses h1',
            'body.path-my [role="main"] h1',
            'body.path-my #page-content h1',
            '#page-content [role="main"] h1',
            '#page-content h1',
            'h1'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var h = document.querySelector(selectors[i]);
            if (h && textOf(h).indexOf('my courses') !== -1) {
                return h;
            }
        }
        return document.querySelector('#page-content h1, [role="main"] h1, h1');
    }

    function findWideContainer(heading) {
        var selectors = [
            'body#page-my-courses #region-main',
            'body#page-my-courses [role="main"]',
            'body#page-my-courses #page-content',
            '#region-main',
            '[role="main"]',
            '#page-content',
            '.main-inner',
            '.container-fluid',
            '.container'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (!el || (heading && !el.contains(heading))) {
                continue;
            }
            var rect = el.getBoundingClientRect ? el.getBoundingClientRect() : {width: 0};
            if (rect.width > Math.min(window.innerWidth * 0.55, 720)) {
                return el;
            }
        }
        return heading ? heading.parentElement : null;
    }

    function placeMyCoursesButton() {
        var wrap = document.getElementById('sental-my-courses-action-wrap');
        if (!wrap) {
            return false;
        }

        // Admin/teacher/manager: put SENTAL document upload beside Moodle's own
        // Manage courses / Create course buttons.
        if (wrap.querySelector('.sental-my-courses-upload-btn')) {
            var actiongroup = findAdminActionGroup();
            if (actiongroup) {
                actiongroup.classList.add('sental-my-courses-admin-action-group');
                actiongroup.appendChild(wrap);
                wrap.dataset.sentalMoved = '1';
                return true;
            }
        }

        // Student: put My certifications on the far-right of the My courses heading row.
        var heading = findHeading();
        if (!heading) {
            return false;
        }
        var container = findWideContainer(heading);
        if (!container) {
            return false;
        }

        var row = document.getElementById('sental-my-courses-title-row');
        if (!row) {
            row = document.createElement('div');
            row.id = 'sental-my-courses-title-row';
            row.className = 'sental-my-courses-title-row';
            container.insertBefore(row, container.firstChild);
            row.appendChild(heading);
        } else if (!row.contains(heading)) {
            row.insertBefore(heading, row.firstChild);
        }
        row.appendChild(wrap);
        wrap.dataset.sentalMoved = '1';
        return true;
    }

    function run() {
        attempts++;
        placeMyCoursesButton();
        if (attempts < 20) {
            window.setTimeout(run, 250);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
    window.addEventListener('load', run);
})();
JS);
        }
        return;
    }

    // Do not render SENTAL action buttons on the course view page.
    // Requirement: these buttons must only appear on /my/courses.php.
    return;
}


/**
 * Add course navigation items.
 *
 * Course-page SENTAL links are intentionally not added here.
 * Requirement: My certifications / SENTAL document upload buttons should only
 * appear on the My courses page, not inside individual course pages.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_sentaldocupload_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    return;
}

/**
 * Add navigation links.
 *
 * @param global_navigation $navigation
 */
function local_sentaldocupload_extend_navigation(global_navigation $navigation) {
    if (!isloggedin() || isguestuser()) {
        return;
    }
    $context = context_system::instance();

    $navigation->add(
        get_string('certifications', 'local_sentaldocupload'),
        new moodle_url('/local/sentaldocupload/mydocuments.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_sentaldocupload_certifications'
    );

    if (has_capability('local/sentaldocupload:manage', $context)) {
        $navigation->add(
            get_string('manualdocumentupload', 'local_sentaldocupload'),
            new moodle_url('/local/sentaldocupload/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_sentaldocupload_upload'
        );
    }
}
