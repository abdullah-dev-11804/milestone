<?php
// Legacy redirect: bulk upload is now available on the main upload page.

require_once(__DIR__ . '/../../config.php');
redirect(new moodle_url('/local/sentaldocupload/index.php'));
