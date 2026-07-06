<?php
// Installation hooks for local_sentaldocupload.

defined('MOODLE_INTERNAL') || die();

/**
 * Install hook.
 *
 * Do not create MySQL triggers here. Many Moodle/IOMAD installations run with
 * binary logging enabled and without the SUPER privilege, so CREATE TRIGGER fails
 * during plugin install/upgrade.
 *
 * Audit rows are append-only through the plugin UI: no edit/delete actions are
 * provided, including for superadmins. If strict database-level blocking is
 * required, a DBA can add triggers manually using a privileged DB account.
 *
 * @return bool
 */
function xmldb_local_sentaldocupload_install(): bool {
    return true;
}
