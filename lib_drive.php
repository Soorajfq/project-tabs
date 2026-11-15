<?php
// Google Drive backup: sign-in with your own Google account, upload a zip, keep the newest N.
// Uses only the "drive.file" permission, so the app can see only the files it creates itself.
require_once __DIR__ . '/lib_backup.php';

class DriveException extends RuntimeException {}

const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const DRIVE_FOLDER_NAME = 'Project Tabs backups';

// Google's addresses. config.php may override them with keys of the same name (used for testing only).
function g_url(string $key): string {
    $defaults = [
        'google_auth_url'   => 'https://accounts.google.com/o/oauth2/v2/auth',
        'google_token_url'  => 'https://oauth2.googleapis.com/token',
        'google_revoke_url' => 'https://oauth2.googleapis.com/revoke',
        'google_api_base'   => 'https://www.googleapis.com/drive/v3',
        'google_upload_base' => 'https://www.googleapis.com/upload/drive/v3',
    ];
    $v = cfg($key);
    return $v ? rtrim((string)$v, '/') : $defaults[$key];
}

function drive_redirect_uri(): string {
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir . '/gdrive.php';
}

/** Small cURL wrapper. Options: bearer, form, json, headers, infile, timeout. */
function g_http(string $method, string $url, array $o = []): array {
    $ch = curl_init($url);
    $headers = $o['headers'] ?? [];
    if (!empty($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)($o['timeout'] ?? 60));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    if (isset($o['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($o['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json']));
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
    } elseif (isset($o['infile'])) {
        $fh = fopen($o['infile'], 'rb');
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($o['infile']));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$resHeaders) {
        $p = explode(':', $line, 2);
        if (count($p) === 2) {
            $resHeaders[strtolower(trim($p[0]))] = trim($p[1]);
        }
        return strlen($line);
    });
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new DriveException('Could not reach Google: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (isset($fh) && is_resource($fh)) {
        fclose($fh);
    }
    return ['status' => $status, 'headers' => $resHeaders, 'body' => (string)$body];
}

function g_error_text(array $r): string {
    $j = json_decode($r['body'], true);
    $msg = $j['error']['message'] ?? ($j['error_description'] ?? ($j['error'] ?? ''));
    if (is_array($msg)) {
        $msg = json_encode($msg);
    }
    return 'Google said ' . $r['status'] . ($msg ? ': ' . $msg : '.');
}

/* ---------- connection state ---------- */

function drive_state(): array {
    return [
        'client_saved' => (bool)setting_get('g_client_id') && (bool)setting_get('g_client_secret'),
        'client_id' => (string)setting_get('g_client_id', ''),
        'connected' => (bool)setting_get('g_refresh_token'),
        'last_ok' => (string)setting_get('g_last_ok', ''),
        'last_name' => (string)setting_get('g_last_name', ''),
        'last_bytes' => (int)setting_get('g_last_bytes', '0'),
        'last_error' => (string)setting_get('g_last_error', ''),
    ];
}

function drive_save_client(string $id, string $secret): void {
    $id = trim($id);
    $secret = trim($secret);
    if ($id === '' || $secret === '') {
        throw new DriveException('Enter both the Client ID and the Client secret.');
    }
    if ($id !== setting_get('g_client_id')) {
        // A different Google app: the old connection no longer applies.
        setting_del('g_refresh_token');
        setting_del('g_folder_id');
    }
    setting_set('g_client_id', $id);
    setting_set('g_client_secret', $secret);
}

function drive_auth_start_url(string $state): string {
    $id = (string)setting_get('g_client_id', '');
    if ($id === '' || !setting_get('g_client_secret')) {
        throw new DriveException('Save your Google Client ID and secret first.');
    }
    return g_url('google_auth_url') . '?' . http_build_query([
        'client_id' => $id,
        'redirect_uri' => drive_redirect_uri(),
        'response_type' => 'code',
        'scope' => DRIVE_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
    ]);
}

function drive_finish_auth(string $code): void {
    $r = g_http('POST', g_url('google_token_url'), ['form' => [
        'code' => $code,
        'client_id' => setting_get('g_client_id'),
        'client_secret' => setting_get('g_client_secret'),
        'redirect_uri' => drive_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]]);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
        throw new DriveException('Google did not accept the sign-in. ' . g_error_text($r));
    }
    if (empty($j['refresh_token'])) {
        throw new DriveException('Google did not give a long-lived key. Open myaccount.google.com/permissions, remove "Project Tabs", and connect again.');
    }
    setting_set('g_refresh_token', (string)$j['refresh_token']);
    setting_del('g_folder_id');
    setting_set('g_last_error', '');
}

function drive_disconnect(): void {
    $rt = (string)setting_get('g_refresh_token', '');
    if ($rt !== '') {
        try {
            g_http('POST', g_url('google_revoke_url') . '?' . http_build_query(['token' => $rt]), ['timeout' => 20]);
        } catch (Throwable $e) {
            // Best effort. The key is removed from this server either way.
        }
    }
    setting_del('g_refresh_token');
    setting_del('g_folder_id');
}

/* ---------- Drive calls ---------- */

function drive_access_token(): string {
    $id = (string)setting_get('g_client_id', '');
    $secret = (string)setting_get('g_client_secret', '');
    $rt = (string)setting_get('g_refresh_token', '');
    if ($id === '' || $secret === '' || $rt === '') {
        throw new DriveException('Google Drive is not connected. Open Settings and connect it.');
    }
    $r = g_http('POST', g_url('google_token_url'), ['form' => [
        'client_id' => $id, 'client_secret' => $secret, 'refresh_token' => $rt, 'grant_type' => 'refresh_token',
    ]]);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
        if (is_array($j) && ($j['error'] ?? '') === 'invalid_grant') {
            setting_del('g_refresh_token');
            setting_del('g_folder_id');
            throw new DriveException('Google ended the connection (it expired or was removed). Open Settings and connect Google Drive again.');
        }
        throw new DriveException('Google refused the sign-in. ' . g_error_text($r));
    }
    return (string)$j['access_token'];
}

function drive_folder_id(string $token): string {
    $id = (string)setting_get('g_folder_id', '');
    if ($id !== '') {
        $r = g_http('GET', g_url('google_api_base') . '/files/' . rawurlencode($id) . '?fields=id,trashed', ['bearer' => $token]);
        $j = json_decode($r['body'], true);
        if ($r['status'] === 200 && is_array($j) && empty($j['trashed'])) {
            return $id;
        }
    }
    $r = g_http('POST', g_url('google_api_base') . '/files?fields=id', ['bearer' => $token, 'json' => [
        'name' => DRIVE_FOLDER_NAME, 'mimeType' => 'application/vnd.google-apps.folder',
    ]]);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || empty($j['id'])) {
        throw new DriveException('Could not create the backup folder in Drive. ' . g_error_text($r));
    }
    setting_set('g_folder_id', (string)$j['id']);
    return (string)$j['id'];
}

function drive_upload(string $token, string $folderId, string $path, string $name): array {
    $size = filesize($path);
    $init = g_http('POST', g_url('google_upload_base') . '/files?uploadType=resumable&fields=id,name,size', [
        'bearer' => $token,
        'json' => ['name' => $name, 'parents' => [$folderId], 'mimeType' => 'application/zip'],
        'headers' => ['X-Upload-Content-Type: application/zip', 'X-Upload-Content-Length: ' . $size],
    ]);
    $loc = $init['headers']['location'] ?? '';
    if ($init['status'] !== 200 || $loc === '') {
        throw new DriveException('Could not start the upload to Drive. ' . g_error_text($init));
    }
    $put = g_http('PUT', $loc, ['infile' => $path, 'headers' => ['Content-Type: application/zip'], 'timeout' => 1800]);
    if (!in_array($put['status'], [200, 201], true)) {
        throw new DriveException('The upload to Drive did not finish. ' . g_error_text($put));
    }
    $j = json_decode($put['body'], true);
    return is_array($j) ? $j : [];
}

/** Deletes the oldest backups in the Drive folder so that only $keep remain. */
function drive_prune(string $token, string $folderId, int $keep): int {
    $q = "'" . $folderId . "' in parents and trashed = false";
    $r = g_http('GET', g_url('google_api_base') . '/files?' . http_build_query([
        'q' => $q, 'orderBy' => 'createdTime desc', 'pageSize' => 1000, 'fields' => 'files(id,name,createdTime)',
    ]), ['bearer' => $token]);
    $j = json_decode($r['body'], true);
    if ($r['status'] !== 200 || !isset($j['files'])) {
        throw new DriveException('Could not list old backups. ' . g_error_text($r));
    }
    $mine = array_values(array_filter($j['files'], function ($f) {
        return strpos($f['name'] ?? '', 'project-tabs-backup-') === 0;
    }));
    $deleted = 0;
    foreach (array_slice($mine, $keep) as $f) {
        $d = g_http('DELETE', g_url('google_api_base') . '/files/' . rawurlencode($f['id']), ['bearer' => $token]);
        if (in_array($d['status'], [200, 204], true)) {
            $deleted++;
        }
    }
    return $deleted;
}

/** Builds a backup and uploads it. Records the outcome so Settings can show it. */
function run_drive_backup(): array {
    @set_time_limit(0);
    $dest = tmp_dir() . '/drive-' . bin2hex(random_bytes(6)) . '.zip';
    try {
        $stats = build_backup_zip($dest);
        $token = drive_access_token();
        $folder = drive_folder_id($token);
        $name = backup_filename();
        drive_upload($token, $folder, $dest, $name);
        $keep = max(1, (int)(cfg('backup_keep') ?: 14));
        $pruned = 0;
        $pruneNote = '';
        try {
            $pruned = drive_prune($token, $folder, $keep);
        } catch (Throwable $e) {
            $pruneNote = ' (the new backup is saved, but old ones could not be cleaned up: ' . $e->getMessage() . ')';
        }
        setting_set('g_last_ok', gmdate('c'));
        setting_set('g_last_name', $name);
        setting_set('g_last_bytes', (string)$stats['bytes']);
        setting_set('g_last_error', trim($pruneNote));
        return $stats + ['name' => $name, 'pruned' => $pruned];
    } catch (Throwable $e) {
        setting_set('g_last_error', gmdate('Y-m-d H:i') . ' UTC: ' . $e->getMessage());
        throw $e;
    } finally {
        if (is_file($dest)) {
            @unlink($dest);
        }
    }
}
