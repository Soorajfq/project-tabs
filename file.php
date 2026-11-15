<?php
// Serves an uploaded file, only to a signed-in user.
require __DIR__ . '/lib.php';
security_headers();
start_session();

try {
    $user = current_user();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database problem.');
}
if (!$user) {
    http_response_code(401);
    exit('Please sign in.');
}

$st = db()->prepare('SELECT * FROM files WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$path = $f ? upload_path($f['stored_name']) : '';
if (!$f || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$inlineOk = (bool)$f['is_image'] && empty($_GET['dl']);
$name = $f['original_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

header('Content-Type: ' . ($inlineOk ? $f['mime'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\' data:');
header('Cache-Control: private, max-age=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
if (function_exists('session_write_close')) {
    session_write_close();
}
readfile($path);
