<?php
// JSON API used by the app. Everything here needs a signed-in session.
require_once __DIR__ . '/lib_drive.php';
security_headers();
start_session();

try {
    $user = current_user();
} catch (Throwable $e) {
    error_log('api auth: ' . $e->getMessage());
    fail('Database problem. Check config.php.', 500);
}
if (!$user) {
    fail('Please sign in again.', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

/* ---------- small helpers ---------- */

function str_in($v, int $max): string {
    $v = trim((string)$v);
    return mb_substr($v, 0, $max, 'UTF-8');
}

function color_in($v): string {
    $v = (string)$v;
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : '#475569';
}

function file_row(array $f): array {
    return [
        'id' => (int)$f['id'],
        'name' => $f['original_name'],
        'size' => (int)$f['size'],
        'mime' => $f['mime'],
        'image' => (bool)$f['is_image'],
    ];
}

function section_row(array $s, array $files = []): array {
    return [
        'id' => (int)$s['id'],
        'project_id' => (int)$s['project_id'],
        'title' => $s['title'],
        'color' => $s['color'],
        'body' => $s['body'],
        'files' => array_map('file_row', $files),
    ];
}

function project_exists(int $id): bool {
    $st = db()->prepare('SELECT 1 FROM projects WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function stored_names_for(string $where, array $args): array {
    $st = db()->prepare("SELECT f.stored_name FROM files f JOIN sections s ON s.id = f.section_id WHERE $where");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function renumber_sections(int $projectId, array $orderedIds): void {
    $st = db()->prepare('UPDATE sections SET position = ? WHERE id = ? AND project_id = ?');
    foreach (array_values($orderedIds) as $i => $id) {
        $st->execute([$i, (int)$id, $projectId]);
    }
}

function touch_project(int $id): void {
    db()->prepare('UPDATE projects SET updated_at = ? WHERE id = ?')->execute([now(), $id]);
}

function php_upload_limit_message(): string {
    return 'The server refused the file because it is bigger than the PHP upload limit (' . ini_get('upload_max_filesize') . '). See INSTALL.md, step "Upload size".';
}

/* ---------- GET ---------- */

if ($method === 'GET') {
    $a = $_GET['a'] ?? '';
    if ($a === 'bootstrap') {
        $projects = $pdo->query('SELECT p.id, p.name, (SELECT COUNT(*) FROM sections s WHERE s.project_id = p.id) AS cnt FROM projects p ORDER BY p.id DESC')->fetchAll();
        $tray = $pdo->query('SELECT id, title, color FROM tray_titles ORDER BY position, id')->fetchAll();
        json_out([
            'me' => ['username' => $user['username'], 'totp' => (bool)$user['totp_enabled']],
            'projects' => array_map(function ($p) {
                return ['id' => (int)$p['id'], 'name' => $p['name'], 'count' => (int)$p['cnt']];
            }, $projects),
            'tray' => array_map(function ($t) {
                return ['id' => (int)$t['id'], 'title' => $t['title'], 'color' => $t['color']];
            }, $tray),
        ]);
    }
    if ($a === 'drive') {
        json_out(drive_state() + [
            'redirect_uri' => drive_redirect_uri(),
            'cron_command' => '/usr/local/bin/php ' . APP_DIR . '/cron_backup.php',
        ]);
    }
    if ($a === 'project') {
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare('SELECT id, name FROM projects WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            fail('Project not found.', 404);
        }
        $st = $pdo->prepare('SELECT * FROM sections WHERE project_id = ? ORDER BY position, id');
        $st->execute([$id]);
        $sections = $st->fetchAll();
        $byId = [];
        foreach ($sections as $s) {
            $byId[(int)$s['id']] = [];
        }
        if ($byId) {
            $in = implode(',', array_map('intval', array_keys($byId)));
            foreach ($pdo->query("SELECT * FROM files WHERE section_id IN ($in) ORDER BY id")->fetchAll() as $f) {
                $byId[(int)$f['section_id']][] = $f;
            }
        }
        json_out([
            'project' => ['id' => (int)$p['id'], 'name' => $p['name']],
            'sections' => array_map(function ($s) use ($byId) {
                return section_row($s, $byId[(int)$s['id']]);
            }, $sections),
        ]);
    }
    fail('Unknown request.', 404);
}

if ($method !== 'POST') {
    fail('Method not allowed.', 405);
}

/* ---------- POST ---------- */

if (!csrf_ok((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    fail('Your session expired. Reload the page.', 403);
}

$isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0;
if ($isMultipart) {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail(php_upload_limit_message(), 413);
    }
    $in = $_POST;
} else {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $in = [];
    }
}
$a = (string)($in['a'] ?? '');

try {
    switch ($a) {

    /* --- projects --- */
    case 'project.create': {
        $name = str_in($in['name'] ?? '', 190);
        if ($name === '') {
            fail('Give the project a name.');
        }
        $pdo->prepare('INSERT INTO projects (name, created_at, updated_at) VALUES (?, ?, ?)')->execute([$name, now(), now()]);
        json_out(['id' => (int)$pdo->lastInsertId(), 'name' => $name]);
    }

    case 'project.update': {
        $name = str_in($in['name'] ?? '', 190);
        $pdo->prepare('UPDATE projects SET name = ?, updated_at = ? WHERE id = ?')->execute([$name === '' ? 'Untitled' : $name, now(), (int)($in['id'] ?? 0)]);
        json_out(['ok' => true]);
    }

    case 'project.delete': {
        $id = (int)($in['id'] ?? 0);
        $names = stored_names_for('s.project_id = ?', [$id]);
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        delete_stored_files($names);
        json_out(['ok' => true]);
    }

    /* --- sections --- */
    case 'section.add': {
        $pid = (int)($in['project_id'] ?? 0);
        if (!project_exists($pid)) {
            fail('Project not found.', 404);
        }
        $title = str_in($in['title'] ?? '', 120);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO sections (project_id, title, color, body, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$pid, $title === '' ? 'Untitled' : $title, color_in($in['color'] ?? ''), '', 999999, now(), now()]);
        $newId = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT id FROM sections WHERE project_id = ? AND id <> ? ORDER BY position, id');
        $st->execute([$pid, $newId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $idx = max(0, min(count($ids), (int)($in['index'] ?? count($ids))));
        array_splice($ids, $idx, 0, [$newId]);
        renumber_sections($pid, $ids);
        $pdo->commit();
        touch_project($pid);
        $st = $pdo->prepare('SELECT * FROM sections WHERE id = ?');
        $st->execute([$newId]);
        json_out(section_row($st->fetch()));
    }

    case 'section.update': {
        $id = (int)($in['id'] ?? 0);
        $sets = [];
        $args = [];
        if (array_key_exists('title', $in)) {
            $t = str_in($in['title'], 120);
            $sets[] = 'title = ?';
            $args[] = $t === '' ? 'Untitled' : $t;
        }
        if (array_key_exists('color', $in)) {
            $sets[] = 'color = ?';
            $args[] = color_in($in['color']);
        }
        if (array_key_exists('body', $in)) {
            $b = (string)$in['body'];
            if (strlen($b) > 4000000) {
                fail('That text is too long to save (over 4 MB).');
            }
            $sets[] = 'body = ?';
            $args[] = $b;
        }
        if ($sets) {
            $sets[] = 'updated_at = ?';
            $args[] = now();
            $args[] = $id;
            $pdo->prepare('UPDATE sections SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        }
        json_out(['ok' => true]);
    }

    case 'section.reorder': {
        $pid = (int)($in['project_id'] ?? 0);
        $ids = array_map('intval', (array)($in['ids'] ?? []));
        $st = $pdo->prepare('SELECT id FROM sections WHERE project_id = ?');
        $st->execute([$pid]);
        $have = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        sort($have);
        $check = $ids;
        sort($check);
        if ($have !== $check) {
            fail('The section list is out of date. Reload the page.', 409);
        }
        $pdo->beginTransaction();
        renumber_sections($pid, $ids);
        $pdo->commit();
        json_out(['ok' => true]);
    }

    case 'section.delete': {
        $id = (int)($in['id'] ?? 0);
        $names = stored_names_for('s.id = ?', [$id]);
        $pdo->prepare('DELETE FROM sections WHERE id = ?')->execute([$id]);
        delete_stored_files($names);
        json_out(['ok' => true]);
    }

    /* --- tray titles --- */
    case 'tray.add': {
        $t = str_in($in['title'] ?? '', 120);
        if ($t === '') {
            fail('Type a title first.');
        }
        $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM tray_titles')->fetchColumn();
        $color = color_in($in['color'] ?? '');
        $pdo->prepare('INSERT INTO tray_titles (title, color, position) VALUES (?, ?, ?)')->execute([$t, $color, $pos]);
        json_out(['id' => (int)$pdo->lastInsertId(), 'title' => $t, 'color' => $color]);
    }

    case 'tray.update': {
        $id = (int)($in['id'] ?? 0);
        if (array_key_exists('title', $in)) {
            $t = str_in($in['title'], 120);
            if ($t !== '') {
                $pdo->prepare('UPDATE tray_titles SET title = ? WHERE id = ?')->execute([$t, $id]);
            }
        }
        if (array_key_exists('color', $in)) {
            $pdo->prepare('UPDATE tray_titles SET color = ? WHERE id = ?')->execute([color_in($in['color']), $id]);
        }
        json_out(['ok' => true]);
    }

    case 'tray.delete': {
        $pdo->prepare('DELETE FROM tray_titles WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        json_out(['ok' => true]);
    }

    /* --- files --- */
    case 'file.upload': {
        $sid = (int)($in['section_id'] ?? 0);
        $st = $pdo->prepare('SELECT id, project_id FROM sections WHERE id = ?');
        $st->execute([$sid]);
        $sec = $st->fetch();
        if (!$sec) {
            fail('Section not found.', 404);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || !isset($f['error'])) {
            fail('No file received.');
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            fail(php_upload_limit_message(), 413);
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            fail('The upload did not finish. Try again.');
        }
        $maxMb = (int)(cfg('max_upload_mb') ?: 20);
        if ($f['size'] > $maxMb * 1048576) {
            fail("That file is over $maxMb MB.", 413);
        }
        if ($f['size'] <= 0) {
            fail('That file is empty.');
        }
        ensure_storage();
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($f['tmp_name']);
        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? 1 : 0;
        $orig = str_in(basename(str_replace('\\', '/', (string)($f['name'] ?? 'file'))), 255);
        $orig = preg_replace('/[\x00-\x1f\x7f]/u', '', $orig);
        if ($orig === '') {
            $orig = 'file';
        }
        $stored = bin2hex(random_bytes(16));
        if (!move_uploaded_file($f['tmp_name'], upload_path($stored))) {
            fail('The server could not save the file. Check that the storage folder is writable.', 500);
        }
        @chmod(upload_path($stored), 0640);
        $pdo->prepare('INSERT INTO files (section_id, original_name, stored_name, mime, size, is_image, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$sid, $orig, $stored, substr($mime, 0, 120), (int)$f['size'], $isImage, now()]);
        $fileId = (int)$pdo->lastInsertId();
        touch_project((int)$sec['project_id']);
        json_out(file_row([
            'id' => $fileId, 'original_name' => $orig, 'size' => $f['size'],
            'mime' => $mime, 'is_image' => $isImage,
        ]));
    }

    case 'file.delete': {
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare('SELECT stored_name FROM files WHERE id = ?');
        $st->execute([$id]);
        $name = $st->fetchColumn();
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
        if ($name) {
            delete_stored_files([$name]);
        }
        json_out(['ok' => true]);
    }

    /* --- account: two-factor and password --- */
    case '2fa.begin': {
        $secret = totp_new_secret();
        $_SESSION['totp_pending'] = $secret;
        $label = rawurlencode('Project Tabs') . ':' . rawurlencode($user['username']);
        json_out([
            'secret' => $secret,
            'uri' => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode('Project Tabs') . '&algorithm=SHA1&digits=6&period=30',
        ]);
    }

    case '2fa.enable': {
        $secret = (string)($_SESSION['totp_pending'] ?? '');
        if ($secret === '') {
            fail('Start again: tap "Set up" first.');
        }
        $step = totp_match($secret, (string)($in['code'] ?? ''));
        if ($step === null) {
            fail('That code is not right. Check the code in your app and try again.');
        }
        $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?')->execute([$secret, $step, $user['id']]);
        unset($_SESSION['totp_pending']);
        json_out(['recovery' => make_recovery_codes((int)$user['id'])]);
    }

    case '2fa.disable': {
        if (!password_verify((string)($in['password'] ?? ''), $user['password_hash'])) {
            fail('That password is not right.');
        }
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?')->execute([$user['id']]);
        $pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$user['id']]);
        json_out(['ok' => true]);
    }

    case '2fa.recovery': {
        if (!$user['totp_enabled']) {
            fail('Turn on two-factor sign-in first.');
        }
        if (!password_verify((string)($in['password'] ?? ''), $user['password_hash'])) {
            fail('That password is not right.');
        }
        json_out(['recovery' => make_recovery_codes((int)$user['id'])]);
    }

    case 'password.change': {
        $new = (string)($in['new'] ?? '');
        if (!password_verify((string)($in['current'] ?? ''), $user['password_hash'])) {
            fail('Your current password is not right.');
        }
        if (strlen($new) < 10) {
            fail('The new password must be at least 10 characters.');
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        json_out(['ok' => true]);
    }

    /* --- backups to Google Drive --- */
    case 'drive.client': {
        drive_save_client((string)($in['client_id'] ?? ''), (string)($in['client_secret'] ?? ''));
        json_out(['ok' => true]);
    }

    case 'drive.disconnect': {
        drive_disconnect();
        json_out(['ok' => true]);
    }

    case 'drive.backup': {
        $r = run_drive_backup();
        json_out(['ok' => true, 'name' => $r['name'], 'bytes' => $r['bytes'], 'projects' => $r['projects'], 'files' => $r['files']]);
    }

    case 'logout': {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(['ok' => true]);
    }

    default:
        fail('Unknown request.', 404);
    }
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api ' . $a . ': ' . $e->getMessage());
    fail($e->getMessage(), 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api ' . $a . ': ' . $e->getMessage());
    fail('Something went wrong on the server. Try again.', 500);
}
