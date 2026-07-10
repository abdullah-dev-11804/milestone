<?php
namespace local_sentaldocupload\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('sental_modeb_doc_user', [
            'documentid' => 'The uploaded document record linked to the user.',
            'userid' => 'The user who can access the document.',
            'timecreated' => 'Time the user link was created.',
        ], 'Links uploaded manual documents to users.');

        $collection->add_database_table('sental_modeb_audit', [
            'documentid' => 'The document acted upon.',
            'versionid' => 'The document version acted upon.',
            'userid' => 'The learner related to the action, if known.',
            'actorid' => 'The user who performed the action.',
            'ipaddress' => 'The IP address used for the action.',
            'actiontype' => 'The action type.',
            'timecreated' => 'The time of the action.',
        ], 'Immutable audit trail for manual document actions.');

        return $collection;
    }
}
