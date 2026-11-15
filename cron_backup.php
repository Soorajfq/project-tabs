<?php
// Run by cPanel > Cron Jobs once a day. Not reachable from the browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from cPanel Cron Jobs, not from a browser.\n");
}
require __DIR__ . '/lib_drive.php';

try {
    $r = run_drive_backup();
    echo date('Y-m-d H:i') . ' Backup saved to Google Drive: ' . $r['name'] . ' (' . round($r['bytes'] / 1048576, 1) . ' MB, '
        . $r['projects'] . ' projects, ' . $r['files'] . ' files). Old backups removed: ' . $r['pruned'] . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i') . ' Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
