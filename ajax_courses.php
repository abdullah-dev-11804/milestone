<?php
// AJAX: return all selected user's courses. Manual scan upload is available for every course.

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();
$context = context_system::instance();
$requestedcourseid = optional_param('courseid', 0, PARAM_INT);
$canmanageglobal = has_capability('local/sentaldocupload:manage', $context);

if (!$canmanageglobal) {
    local_sentaldocupload_require_upload_for_course($requestedcourseid);
}

$userid = required_param('userid', PARAM_INT);
$companyid = optional_param('companyid', 0, PARAM_INT);
if ($companyid <= 0) {
    $companyid = local_sentaldocupload_get_current_company_id();
}

$result = [
    'success' => true,
    'courses' => [],
];

try {
    if ($userid > 0) {
        if ($companyid > 0 && !local_sentaldocupload_user_in_company($userid, $companyid)) {
            $result['success'] = false;
            $result['message'] = get_string('usernotincompany', 'local_sentaldocupload');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result);
            exit;
        }

        $courses = local_sentaldocupload_get_user_courses($userid);
        foreach ($courses as $course) {
            if (!$canmanageglobal && $requestedcourseid > 0 && (int)$course->id !== $requestedcourseid) {
                continue;
            }
            if (!local_sentaldocupload_can_upload_for_course((int)$course->id)) {
                continue;
            }
            $validitydays = local_sentaldocupload_get_course_validity_days((int)$course->id);
            $haseds = local_sentaldocupload_user_course_has_eds_document((int)$course->id, $userid);
            $participants = [];

            foreach (local_sentaldocupload_get_course_participants((int)$course->id, $userid, $companyid) as $participant) {
                $label = $participant->fullname;
                if (!empty($participant->email)) {
                    $label .= ' (' . $participant->email . ')';
                }
                $participants[] = [
                    'id' => (int)$participant->id,
                    'label' => $label,
                    'search' => core_text::strtolower($label),
                ];
            }

            $result['courses'][] = [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname),
                'shortname' => format_string($course->shortname),
                'validitydays' => $validitydays,
                'haseds' => $haseds,
                'uploadpath' => $haseds ? get_string('uploadpaths_eds_manual', 'local_sentaldocupload') : get_string('uploadpaths_manual', 'local_sentaldocupload'),
                'participants' => $participants,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('SENTAL course AJAX failed: ' . $e->getMessage());
    $result = [
        'success' => false,
        'message' => get_string('course_load_error', 'local_sentaldocupload'),
        'courses' => [],
    ];
}

if ($userid <= 0) {
    $result['success'] = false;
    $result['message'] = get_string('selectuser', 'local_sentaldocupload');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
