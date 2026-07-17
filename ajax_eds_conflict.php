<?php
// AJAX: check whether a Type 1 upload would be blocked by an active EDS document.

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();

$context = context_system::instance();
$PAGE->set_context($context);
$canmanageglobal = has_capability('local/sentaldocupload:manage', $context);
$requestedcourseid = optional_param('requestedcourseid', 0, PARAM_INT);

if (!$canmanageglobal) {
    local_sentaldocupload_require_upload_for_course($requestedcourseid);
}

$courseid = required_param('courseid', PARAM_INT);
$userids = optional_param_array('userids', [], PARAM_INT);
$companyid = optional_param('companyid', 0, PARAM_INT);
if ($companyid <= 0) {
    $companyid = local_sentaldocupload_get_current_company_id();
}

$result = [
    'success' => true,
    'blocked' => false,
    'message' => '',
];

try {
    if ($courseid <= 0 || empty($userids)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    if (!local_sentaldocupload_can_upload_for_course($courseid)) {
        throw new moodle_exception('courseuploadnotallowed', 'local_sentaldocupload');
    }
    if (!$canmanageglobal && $requestedcourseid > 0 && $courseid !== $requestedcourseid) {
        throw new moodle_exception('courseuploadnotallowed', 'local_sentaldocupload');
    }

    $course = get_course($courseid);
    $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));

    foreach ($userids as $userid) {
        if ($companyid > 0 && !local_sentaldocupload_user_in_company($userid, $companyid)) {
            throw new moodle_exception('usernotincompany', 'local_sentaldocupload');
        }
        if (!local_sentaldocupload_user_enrolled_in_course($userid, $courseid)) {
            throw new moodle_exception('courseusernotallowed', 'local_sentaldocupload');
        }

        $activeeds = local_sentaldocupload_get_active_eds_course_completion_document($courseid, $userid);
        if (!$activeeds) {
            continue;
        }

        $learner = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,email,username', IGNORE_MISSING);
        $expirytext = empty($activeeds->expirydate)
            ? get_string('noexpiry', 'local_sentaldocupload')
            : userdate((int)$activeeds->expirydate, get_string('strftimedate', 'langconfig'));

        $result['blocked'] = true;
        $result['message'] = get_string('activeedsdocumentblocksupload', 'local_sentaldocupload', (object)[
            'learner' => $learner ? fullname($learner) : (string)$userid,
            'course' => format_string($course->fullname),
            'expiry' => $expirytext,
        ]);
        break;
    }
} catch (Throwable $e) {
    error_log('SENTAL EDS conflict AJAX failed: ' . $e->getMessage());
    $result = [
        'success' => false,
        'blocked' => false,
        'message' => get_string('edsconflictcheckfailed', 'local_sentaldocupload'),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
