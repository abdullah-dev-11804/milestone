<?php
// Language strings for local_sentaldocupload.

$string['pluginname'] = 'SENTAL Documents';
$string['privacy:metadata'] = 'The SENTAL Documents plugin stores manual training document metadata, version history, public-profile visibility flags, completion flags, and audit logs.';

$string['manualdocumentupload'] = 'Manual document upload';
$string['uploaddocuments'] = 'Manual document upload';
$string['documentuploadsettings'] = 'Manual document upload';
$string['opendocumentupload'] = 'Open manual document upload';
$string['manualuploaddescription'] = 'Manual scan upload is available for all selected learner courses, including EDS-linked courses. Type 1 uploads can auto-complete the course; Type 2 uploads are supplementary records.';

$string['validityfieldshortname'] = 'Validity period custom field shortname';
$string['validityfieldshortname_desc'] = 'Course custom field shortname that stores the validity period in days. Default: validity_period.';
$string['ncasigntablename'] = 'NCASign template courses table';
$string['ncasigntablename_desc'] = 'Existing table used to detect whether the EDS upload/generation path is available for a course. Manual scan upload remains available for all courses.';

$string['selectuser'] = 'Select user';
$string['searchuserplaceholder'] = 'Search and select user';
$string['usersearchhelp'] = 'Search and select a user from the currently selected IOMAD company.';
$string['selectcourse'] = 'Select course';
$string['selectcourseafteruser'] = 'Select user first';
$string['allcourseshelp'] = 'All courses for the selected learner are shown. Courses are not filtered by EDS/manual mode.';
$string['nocourses'] = 'This user has no courses.';
$string['course_load_error'] = 'Could not load courses. Check the Moodle developer debug log for details.';
$string['course'] = 'Course';
$string['loadcourses'] = 'Load courses';
$string['validitydays'] = 'Validity days';

$string['issuedate'] = 'Issue date';
$string['expirypreviewpending'] = 'Select course and issue date to calculate expiry date.';
$string['calculatedexpirywithvalue'] = 'Calculated expiry date: {$a->date} ({$a->days} days)';
$string['validityperiodmissingpreview'] = 'Expiry date cannot be calculated because this course validity_period custom field is empty or 0. Set the course custom field value in days, for example 365.';
$string['noexpiry'] = 'No expiry';
$string['expirydate'] = 'Expiry date';

$string['documentfiles'] = 'Document file(s)';
$string['documentfile'] = 'Document file';
$string['documentuploadrow'] = 'Document {$a}';
$string['rowfilehelp'] = 'Click Add file to upload another document. Each file has its own Type 1 / Type 2 classification, label rules, and optional group participants.';
$string['addanotherdocument'] = '+ Add file';
$string['removedocumentrow'] = 'Remove this file';
$string['uploadselecteddocuments'] = 'Upload selected document(s)';
$string['bulkdocumentsuploaded'] = '{$a} document(s) uploaded successfully.';

$string['documenttype'] = 'Document type';
$string['doctype_type1'] = 'Type 1 - Course completion document';
$string['doctype_type2'] = 'Type 2 - Supplementary document';
$string['type1defaultlabel'] = 'Course completion document';
$string['type1mustbepdf'] = 'Type 1 course completion document must be uploaded as a single PDF file.';
$string['customlabel'] = 'Document name / label';
$string['customlabelplaceholder'] = 'Example: Test sheet, inspection record, attendance sheet';
$string['customlabelhelp'] = 'Required for Type 2 supplementary documents. This label helps admins identify the uploaded file.';
$string['missingcustomlabel'] = 'Please enter a custom document name/label for Type 2 supplementary document.';

$string['showinpublicprofile'] = 'Show in Public Profile';
$string['showinpublicprofilehelp'] = 'Tick this only when the Type 1 course completion document should be visible to the student/public profile. If an EDS course-completion document exists, the checkbox is hidden and the scan remains private. Type 2 is never public.';
$string['uploadpaths_manual'] = 'Upload paths: Manual scan upload';
$string['uploadpaths_eds_manual'] = 'Upload paths: EDS + Manual scan upload';

$string['additionalparticipants'] = 'Additional participants';
$string['additionalparticipantshelp'] = 'Optional. Select additional learners from the same course to link this same uploaded file to multiple learners. This applies to Type 1 and Type 2 documents.';
$string['searchparticipantsplaceholder'] = 'Search additional participants in this course';
$string['selectcoursefirstparticipants'] = 'Select course first.';
$string['participantnotallowed'] = 'One or more additional participants are not allowed for this course/company.';
$string['participantsonlyprotocol'] = 'Group upload is available for Type 1 and Type 2 documents.';

$string['missinguser'] = 'Please select a user.';
$string['missingcourse'] = 'Please select a course.';
$string['missingdoctype'] = 'Please select a document type.';
$string['missingfile'] = 'Please select a document file.';
$string['missingbulkfiles'] = 'Select at least one document file before uploading.';
$string['missingissuedate'] = 'Please select an issue date.';
$string['courseusernotallowed'] = 'The selected learner is not enrolled in this course.';
$string['usernotincompany'] = 'The selected user does not belong to the current company.';
$string['filetoolarge'] = 'The selected file is too large. Maximum allowed size is 10 MB.';
$string['invalidfiletype'] = 'Invalid file type. Allowed file types are PDF, JPG, JPEG, and PNG.';

$string['statusactive'] = 'Active';
$string['statusexpiring'] = 'Expiring';
$string['statusexpired'] = 'Expired';
$string['statusnodocument'] = 'No document';

$string['mydocumentsdisabled'] = 'Student document view disabled';
$string['mydocumentsdisabled_desc'] = 'Manual uploaded documents are stored in the database and Moodle File API for admin/teacher records only. Student-side display is handled by the Public Profile priority logic, not this page.';

// Deprecated/legacy strings kept so old URLs/classes do not break.
$string['protocol'] = 'Protocol';
$string['certificate'] = 'Certificate';
$string['completionbook'] = 'Course Completion Book';
$string['coursemode'] = 'Course mode';
$string['modea'] = 'EDS path available';
$string['modeb'] = 'Manual scan upload';
$string['modewarning'] = 'Course-level Mode A / Mode B was removed. Manual scan upload is available on every course.';
$string['setmode'] = 'Save';
$string['coursemode_removed'] = 'Course mode removed';
$string['coursemode_removed_desc'] = 'The confirmed Milestone 2 workflow removed exclusive Mode A / Mode B assignment. Manual scan upload is now available for every course. EDS profile mapping only indicates that the EDS path is also available.';
$string['uploaddocument'] = 'Upload document';
$string['notmodebcourse'] = 'Manual scan upload is available for all courses; this old mode check should not be used.';

$string['publicprofilepriorityimplemented'] = 'Public Profile priority logic enabled';
$string['publicprofilepriorityimplemented_desc'] = 'EDS documents have priority. Type 1 scans are public automatically only when no EDS document exists, or when admin manually checks Show in Public Profile. Type 2 documents are never public.';

$string['versionhistory'] = 'Version history';
$string['openversionhistory'] = 'Open version history';
$string['versionhistorydesc'] = 'Every upload creates a new version. Previous versions are preserved and can be viewed/downloaded by admins and managers.';
$string['versionno'] = 'Version';
$string['currentversionlabel'] = 'Current';
$string['previousversionlabel'] = 'Previous';
$string['uploadedat'] = 'Uploaded at';
$string['uploadedby'] = 'Uploaded by';
$string['learners'] = 'Learners';
$string['downloadfile'] = 'Download file';
$string['noversionhistory'] = 'No uploaded document versions found yet.';
$string['documentid'] = 'Document ID';
$string['file'] = 'File';
$string['historylink'] = 'View version history';

$string['audittrail'] = 'Audit trail';
$string['audittraildesc'] = 'Immutable append-only log of manual document actions. Every upload, replacement, view, download, and Type 1 auto-completion is recorded with timestamp, actor, IP address, document, version, linked learner, and action type. This page has no edit/delete actions, and on MySQL/MariaDB the database blocks UPDATE and DELETE on the audit table.';
$string['noauditlogs'] = 'No audit log entries found yet.';
$string['audit_timestamp'] = 'Timestamp';
$string['audit_actor'] = 'Actor';
$string['audit_ipaddress'] = 'IP address';
$string['audit_document'] = 'Document';
$string['audit_version'] = 'Version';
$string['audit_actiontype'] = 'Action type';
$string['audit_learner'] = 'Learner';
$string['audit_action_upload'] = 'Upload';
$string['audit_action_replace'] = 'Replace';
$string['audit_action_view'] = 'View';
$string['audit_action_download'] = 'Download';
$string['audit_action_course_completed'] = 'Course auto-completed';
$string['audit_action_public_view'] = 'Public profile file view';
$string['viewfile'] = 'View';
$string['filter_search'] = 'Search';
$string['filter_search_placeholder'] = 'Search course, learner, actor, file, or label';
$string['filter_all_courses'] = 'All courses';
$string['filter_all_document_types'] = 'All document types';
$string['filter_all_actions'] = 'All actions';
$string['filter_apply'] = 'Apply filters';
$string['filter_reset'] = 'Reset';
$string['pagination_summary'] = 'Showing 25 entries per page. Total matching entries: {$a->total}.';
$string['filter_student'] = 'Search student';
$string['filter_student_placeholder'] = 'Search and select student by name or email';
$string['learner'] = 'Learner';
$string['coursecompletiondocument'] = 'Course completion document';
$string['certificationstatus'] = 'Status';

$string['certifications'] = 'Certifications';
$string['mycertificationscoursebutton'] = 'My certifications';

$string['sentaldocumentuploadcoursebutton'] = 'SENTAL document upload';
$string['courseuploadnotallowed'] = 'You are not allowed to upload SENTAL documents for this course.';
$string['certifications_desc'] = 'View and download your course documents.';
$string['nocertificationcourses'] = 'No enrolled courses were found for your account.';
$string['selectcoursecard'] = 'Select a course';
$string['latestexpiry'] = 'Latest expiry';
$string['documentscount'] = 'Documents: {$a->count}';
$string['documenttypesforcourse'] = 'Document types - {$a}';
$string['doctype_type1_short'] = 'Course completion';
$string['doctype_type2_short'] = 'Supplementary document';
$string['nodocumentsfortype'] = 'No documents are available for this document type.';
$string['action'] = 'Action';

$string['sentaldocupload:manage'] = 'Manage SENTAL document uploads';
$string['sentaldocupload:viewdocuments'] = 'View SENTAL documents';
