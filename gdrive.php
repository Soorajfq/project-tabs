<?php
// Google sign-in for Drive backups. "?go=1" starts it; Google sends you back here with ?code=...
require __DIR__ . '/lib_drive.php';
security_headers();
start_session();

try {
    $user = current_user();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database problem.');
}
if (!$user) {
    header('Location: login.php');
    exit;
}

function back(string $hash): void {
    header('Location: index.php#' . $hash);
    exit;
}

try {
    if (isset($_GET['go'])) {
        $state = bin2hex(random_bytes(16));
        $_SESSION['g_state'] = $state;
        header('Location: ' . drive_auth_start_url($state));
        exit;
    }
    if (isset($_GET['error'])) {
        setting_set('g_last_error', gmdate('Y-m-d H:i') . ' UTC: Google sign-in was cancelled or refused (' . substr((string)$_GET['error'], 0, 80) . ').');
        back('drive-error');
    }
    if (isset($_GET['code'])) {
        $expected = (string)($_SESSION['g_state'] ?? '');
        unset($_SESSION['g_state']);
        if ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? ''))) {
            throw new DriveException('The sign-in could not be checked. Please try connecting again.');
        }
        drive_finish_auth((string)$_GET['code']);
        back('drive-ok');
    }
    back('');
} catch (Throwable $e) {
    error_log('gdrive: ' . $e->getMessage());
    setting_set('g_last_error', gmdate('Y-m-d H:i') . ' UTC: ' . $e->getMessage());
    back('drive-error');
}
