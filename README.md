# SENTAL Documents (`local_sentaldocupload`)

Milestone 2 confirmation implementation.

## Confirmed workflow implemented in v0.3.1

- Course-level Mode A / Mode B assignment is removed.
- Manual scan upload is available for every selected learner course.
- If a course exists in `local_ncasign_template_courses`, the course also has the EDS upload/generation path.
- Upload page shows all courses for the selected learner.
- Old document types Protocol / Certificate / Course Completion Book are replaced with:
  - Type 1 — Course completion document
  - Type 2 — Supplementary document
- Type 1 must be a single PDF and can auto-complete the selected course for linked learners who are not already completed.
- Type 2 requires a custom document name/label, can be PDF/JPG/PNG, and does not affect completion.
- Group upload is available for Type 1 and Type 2. The same uploaded file can be linked to multiple learners in the same course.
- Public Profile visibility is stored per linked learner:
  - Type 2 is never public.
  - Type 1 is public automatically when no EDS document/path exists for that learner/course.
  - Type 1 is hidden by default when an EDS document/path exists unless admin checks “Show in Public Profile”.

## Main page

`/local/sentaldocupload/index.php`

## Database tables

Existing table names are preserved for upgrade safety:

- `sental_modeb_doc`
- `sental_modeb_doc_user`
- `sental_modeb_doc_version`
- `sental_modeb_audit`

Fields added for this confirmed workflow:

- `customlabel`
- `showinpublicprofile`
- `autocompleted`
- `completiontime`

The `sental_modeb_doc_user` table also stores per-learner public-profile and completion flags for group uploads.

## Notes

The plugin stores files using Moodle File API, not direct plugin folders.

## v0.3.6 Type 1 Auto Completion Rule

Uploading a Type 1 course completion document marks the selected learner and any selected group participants complete in Moodle course completion only when they are not already completed. Already completed learners are left unchanged. Activity/module completion rows are not forced, so activity progress bars remain based on real activity completion.


## v0.3.8 Public Profile priority logic

This version implements the confirmed Public Profile display priority:

1. If an EDS document exists for the learner-course, the EDS document should be shown by the Public Profile page. Uploaded Type 1 scans are stored but hidden unless the admin checked **Show in Public Profile**.
2. If no EDS document exists and a Type 1 scan exists, the Type 1 scan is available automatically for the Public Profile.
3. If no EDS document and no Type 1 scan exists, no completion document is exposed by this plugin.
4. Type 2 supplementary documents are never exposed publicly.

Integration helper:

```php
$state = local_sentaldocupload_get_public_profile_display_state($userid, $courseid);
$scans = local_sentaldocupload_get_public_profile_scans($userid, $courseid);
```

The returned scan records include `publicurl`, which points to `publicfile.php`. That endpoint only serves Type 1 scans when the priority logic allows public display.

## v0.3.9 - Version History phase

- Added admin Version History page: `/local/sentaldocupload/history.php`.
- Every replacement upload creates the next version number (`v1`, `v2`, `v3`, ...).
- Previous versions remain in `{sental_modeb_doc_version}` and Moodle File API; they are not deleted.
- Type 1 uses one course-completion document record per learner/course; replacement uploads become new versions.
- Type 2 uses one supplementary document record per learner/course/custom label; uploading the same label again becomes a new version, while a different label creates a separate supplementary record.


## v0.4.1 Audit Trail phase

- Added `/local/sentaldocupload/audit.php` admin page.
- Records upload, replace, view, download, and course auto-completion actions.
- Audit rows are append-only in plugin code; there are no edit/delete UI actions.
- History page now includes a View action that logs `view` separately from `download`.


## v0.4.2 Audit immutability hardening

The audit trail is append-only. The plugin UI has no edit/delete actions for audit logs. On MySQL/MariaDB installs, the plugin also creates database triggers on the `sental_modeb_audit` table that reject any UPDATE or DELETE operation, including attempts made through Moodle superadmin-level code paths.

Audit rows should only ever be created through `local_sentaldocupload_audit()`.
