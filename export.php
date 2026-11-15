<?php
// Downloads a full backup zip. Needs a signed-in session and the CSRF token (POST).
require __DIR__ . '/lib_backup.php';
security_headers();
start_session();

try {
    $user = current_user();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database problem.');
}
function export_fail(string $msg, int $code): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}
if (!$user) {
    export_fail('Please sign in again.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    export_fail('Use the Download backup button in Settings.', 405);
}
$tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_ok($tok)) {
    export_fail('Your session expired. Reload the page.', 403);
}
session_write_close();
set_time_limit(300);
ignore_user_abort(true);

$dest = tmp_dir() . '/export-' . bin2hex(random_bytes(6)) . '.zip';
register_shutdown_function(function () use ($dest) {
    if (is_file($dest)) {
        @unlink($dest);
    }
});
try {
    build_backup_zip($dest);
} catch (Throwable $e) {
    error_log('export: ' . $e->getMessage());
    export_fail($e instanceof RuntimeException ? $e->getMessage() : 'Could not build the backup.', 500);
}
$name = backup_filename();
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($dest));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
readfile($dest);
