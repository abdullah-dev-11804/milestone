<?php
// Upgrade steps for local_sentaldocupload.

defined('MOODLE_INTERNAL') || die();

/**
 * Add the EDS template-course mapping table when it is missing.
 * This table no longer defines an exclusive course mode. It only means the EDS path exists.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_sentaldocupload_ensure_modea_table($dbman) {
    $table = new xmldb_table('local_ncasign_template_courses');

    if (!$dbman->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('profileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid_uix', XMLDB_INDEX_UNIQUE, ['courseid']);
        $dbman->create_table($table);
        return;
    }

    $fields = [
        'templateid' => new xmldb_field('templateid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid'),
        'profileid' => new xmldb_field('profileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'templateid'),
        'timecreated' => new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'profileid'),
        'timemodified' => new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated'),
        'usermodified' => new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified'),
    ];

    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    $index = new xmldb_index('courseid_uix', XMLDB_INDEX_UNIQUE, ['courseid']);
    if (!$dbman->index_exists($table, $index)) {
        $dbman->add_index($table, $index);
    }
}

/**
 * Add per-version issue/expiry fields to preserve historical upload values.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_sentaldocupload_ensure_version_date_fields($dbman) {
    $table = new xmldb_table('sental_modeb_doc_version');
    if (!$dbman->table_exists($table)) {
        return;
    }

    $fields = [
        new xmldb_field('issuedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'filename'),
        new xmldb_field('expirydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'issuedate'),
        new xmldb_field('validitydays', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'expirydate'),
    ];

    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }
}

/**
 * Add fields for confirmed Milestone 2 manual upload workflow.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_sentaldocupload_ensure_confirmation_fields($dbman) {
    $doctable = new xmldb_table('sental_modeb_doc');
    if ($dbman->table_exists($doctable)) {
        $fields = [
            new xmldb_field('customlabel', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'documenttype'),
            new xmldb_field('showinpublicprofile', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'customlabel'),
            new xmldb_field('publicprofileoverride', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showinpublicprofile'),
            new xmldb_field('autocompleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'publicprofileoverride'),
            new xmldb_field('completiontime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'autocompleted'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($doctable, $field)) {
                $dbman->add_field($doctable, $field);
            }
        }
        $index = new xmldb_index('public_ix', XMLDB_INDEX_NOTUNIQUE, ['showinpublicprofile']);
        if (!$dbman->index_exists($doctable, $index)) {
            $dbman->add_index($doctable, $index);
        }
    }

    $versiontable = new xmldb_table('sental_modeb_doc_version');
    if ($dbman->table_exists($versiontable)) {
        $fields = [
            new xmldb_field('customlabel', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'filename'),
            new xmldb_field('showinpublicprofile', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'customlabel'),
            new xmldb_field('publicprofileoverride', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showinpublicprofile'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($versiontable, $field)) {
                $dbman->add_field($versiontable, $field);
            }
        }
    }
}


/**
 * Add per-learner public profile and auto-completion fields.
 * Needed because a single uploaded file can be linked to multiple learners.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_sentaldocupload_ensure_document_user_fields($dbman) {
    $table = new xmldb_table('sental_modeb_doc_user');
    if (!$dbman->table_exists($table)) {
        return;
    }

    $fields = [
        new xmldb_field('showinpublicprofile', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'userid'),
        new xmldb_field('publicprofileoverride', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showinpublicprofile'),
        new xmldb_field('autocompleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'publicprofileoverride'),
        new xmldb_field('completiontime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'autocompleted'),
    ];

    foreach ($fields as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    $index = new xmldb_index('user_public_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'showinpublicprofile']);
    if (!$dbman->index_exists($table, $index)) {
        $dbman->add_index($table, $index);
    }

    $index = new xmldb_index('user_publicoverride_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'publicprofileoverride']);
    if (!$dbman->index_exists($table, $index)) {
        $dbman->add_index($table, $index);
    }
}

/**
 * Ensure fields needed for dynamic Public Profile priority logic.
 * publicprofileoverride stores the admin's manual checkbox separately from the
 * calculated current visibility flag. This allows scans to hide automatically
 * when an EDS document exists unless admin explicitly enabled public display.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_sentaldocupload_ensure_public_profile_phase_fields($dbman) {
    local_sentaldocupload_ensure_confirmation_fields($dbman);
    local_sentaldocupload_ensure_document_user_fields($dbman);
}


/**
 * Audit immutability setup.
 *
 * IMPORTANT: Shared hosting / managed MySQL often blocks CREATE TRIGGER unless the
 * database user has SUPER or log_bin_trust_function_creators is enabled. Therefore
 * the plugin must not create triggers during Moodle upgrade.
 *
 * The audit trail remains append-only in the Moodle/plugin UI: there are no edit or
 * delete pages/actions/capabilities for audit rows. If DB-level enforcement is required,
 * ask the DBA/server owner to install triggers manually with a privileged account.
 *
 * @return void
 */
function local_sentaldocupload_ensure_audit_immutability_triggers(): void {
    // No-op by design. Do not create MySQL triggers during Moodle upgrade.
    return;
}

/**
 * Upgrade local_sentaldocupload.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_sentaldocupload_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026063002) {
        local_sentaldocupload_ensure_modea_table($dbman);
        upgrade_plugin_savepoint(true, 2026063002, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026063003) {
        upgrade_plugin_savepoint(true, 2026063003, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026063004) {
        local_sentaldocupload_ensure_modea_table($dbman);
        upgrade_plugin_savepoint(true, 2026063004, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026063007) {
        local_sentaldocupload_ensure_modea_table($dbman);
        local_sentaldocupload_ensure_version_date_fields($dbman);
        upgrade_plugin_savepoint(true, 2026063007, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026063008) {
        upgrade_plugin_savepoint(true, 2026063008, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026063009) {
        upgrade_plugin_savepoint(true, 2026063009, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026070101) {
        local_sentaldocupload_ensure_modea_table($dbman);
        local_sentaldocupload_ensure_version_date_fields($dbman);
        local_sentaldocupload_ensure_confirmation_fields($dbman);
        upgrade_plugin_savepoint(true, 2026070101, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026070102) {
        local_sentaldocupload_ensure_document_user_fields($dbman);
        upgrade_plugin_savepoint(true, 2026070102, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026070108) {
        local_sentaldocupload_ensure_public_profile_phase_fields($dbman);
        upgrade_plugin_savepoint(true, 2026070108, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026070109) {
        // Version history UI/code phase. No schema change; existing sental_modeb_doc_version table is used.
        upgrade_plugin_savepoint(true, 2026070109, 'local', 'sentaldocupload');
    }


    if ($oldversion < 2026070111) {
        // Audit trail UI/action phase. Existing sental_modeb_audit table is used.
        upgrade_plugin_savepoint(true, 2026070111, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026070112) {
        // v0.4.2 originally tried to create MySQL triggers here. That failed on hosts without SUPER privilege.
        // Keep the audit trail append-only in plugin UI and complete the savepoint safely.
        upgrade_plugin_savepoint(true, 2026070112, 'local', 'sentaldocupload');
    }

    if ($oldversion < 2026070113) {
        // v0.4.3: trigger-free audit immutability patch for shared hosting. No schema change.
        upgrade_plugin_savepoint(true, 2026070113, 'local', 'sentaldocupload');
    }

    return true;
}
