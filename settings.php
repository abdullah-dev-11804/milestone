<?php
// Admin navigation for local_sentaldocupload.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Open the manual document upload page directly when the admin clicks the plugin item.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_sentaldocupload_settings',
        get_string('pluginname', 'local_sentaldocupload'),
        new moodle_url('/local/sentaldocupload/index.php'),
        'local/sentaldocupload:manage'
    ));
}
