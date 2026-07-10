<?php
// Legacy AJAX: return upload path labels for courses. New badge is server-rendered.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();
$context = context_system::instance();

$courseids = optional_param_array('courseids', [], PARAM_INT);
$result = ['success' => true, 'courses' => []];
foreach (array_unique(array_map('intval', $courseids)) as $courseid) {
    if ($courseid <= 0) {
        continue;
    }
    $result['courses'][$courseid] = [
        'courseid' => $courseid,
        'mode' => local_sentaldocupload_course_has_eds_template_profile($courseid) ? 'edsmanual' : 'manual',
        'label' => local_sentaldocupload_get_course_mode_label($courseid),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
