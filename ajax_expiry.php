<?php
// AJAX: calculate expiry date from course validity_period days + selected issue date.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();
$context = context_system::instance();
$PAGE->set_context($context);
$courseid = required_param('courseid', PARAM_INT);
$issuedatestr = required_param('issuedate', PARAM_RAW_TRIMMED);
local_sentaldocupload_require_upload_for_course($courseid);

$result = [
    'success' => false,
    'message' => get_string('expirypreviewpending', 'local_sentaldocupload'),
];

if ($courseid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedatestr)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

$issuedate = strtotime($issuedatestr . ' 00:00:00');
$validitydays = local_sentaldocupload_get_course_validity_days($courseid);
$expirydate = local_sentaldocupload_calculate_expiry($issuedate, $validitydays);

if ($validitydays <= 0) {
    $result = [
        'success' => true,
        'courseid' => $courseid,
        'issuedate' => $issuedate,
        'issuedateformatted' => userdate($issuedate, get_string('strftimedatefullshort')),
        'validitydays' => 0,
        'expirydate' => null,
        'expirydateformatted' => get_string('noexpiry', 'local_sentaldocupload'),
        'message' => get_string('validityperiodmissingpreview', 'local_sentaldocupload'),
        'warning' => true,
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

$result = [
    'success' => true,
    'courseid' => $courseid,
    'issuedate' => $issuedate,
    'issuedateformatted' => userdate($issuedate, get_string('strftimedatefullshort')),
    'validitydays' => $validitydays,
    'expirydate' => $expirydate,
    'expirydateformatted' => $expirydate ? userdate($expirydate, get_string('strftimedatefullshort')) : get_string('noexpiry', 'local_sentaldocupload'),
    'message' => get_string('calculatedexpirywithvalue', 'local_sentaldocupload', (object)[
        'date' => $expirydate ? userdate($expirydate, get_string('strftimedatefullshort')) : get_string('noexpiry', 'local_sentaldocupload'),
        'days' => $validitydays,
    ]),
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
