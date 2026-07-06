<?php
// Capabilities for local_sentaldocupload.

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/sentaldocupload:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/sentaldocupload:viewdocuments' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
