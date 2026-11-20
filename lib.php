<?php
// Shared helpers: config, database, session, CSRF, TOTP (2FA), responses.

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Project Tabs needs PHP 7.4 or newer. Change the PHP version in cPanel > MultiPHP Manager.');
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
define('APP_DIR', __DIR__);
define('STORAGE_DIR', __DIR__ . '/storage');
ini_set('error_log', STORAGE_DIR . '/error.log');

function cfg(string $key = null) {
    static $c = null;
    if ($c === null) {
        $file = APP_DIR . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Project Tabs has no config.php yet.\nIn File Manager, copy config.sample.php to a new file named config.php, fill in your database details, then reload this page.\n");
        }
        $c = require $file;
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

date_default_timezone_set(cfg('timezone') ?: 'UTC');

function ensure_storage(): void {
    foreach ([STORAGE_DIR, STORAGE_DIR . '/uploads', STORAGE_DIR . '/sessions'] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
            cfg('db_user'),
            cfg('db_pass'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function now(): string {
    return gmdate('Y-m-d H:i:s');
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ensure_storage();
    $life = 86400 * 30;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_only_cookies', '1');
    if (is_dir(STORAGE_DIR . '/sessions') && is_writable(STORAGE_DIR . '/sessions')) {
        session_save_path(STORAGE_DIR . '/sessions');
    }
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    session_name('ptsid');
    session_set_cookie_params([
        'lifetime' => $life,
        'path' => $base,
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(string $given): bool {
    start_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $given);
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    json_out(['error' => $msg], $code);
}

/* ---------- Login throttle (per IP) ---------- */

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function too_many_attempts(): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?');
    $st->execute([client_ip(), gmdate('Y-m-d H:i:s', time() - 900)]);
    return (int)$st->fetchColumn() >= 8;
}

function record_failed_attempt(): void {
    db()->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)')->execute([client_ip(), now()]);
    db()->prepare('DELETE FROM login_attempts WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
}

function clear_attempts(): void {
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}

/* ---------- TOTP (RFC 6238), compatible with Google Authenticator, Authy, 1Password ---------- */

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bin): string {
    $bits = '';
    foreach (str_split($bin) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $s): string {
    $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s));
    $bits = '';
    foreach (str_split($s) as $ch) {
        $bits .= str_pad(decbin(strpos(B32, $ch)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string {
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, int $step): string {
    $hash = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0x0f;
    $v = ((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o + 1]) & 0xff) << 16)
       | ((ord($hash[$o + 2]) & 0xff) << 8) | (ord($hash[$o + 3]) & 0xff);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Returns the matching time step, or null. Allows 30 seconds of clock drift either way. */
function totp_match(string $secret, string $code): ?int {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $now = intdiv(time(), 30);
    $found = null;
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code($secret, $now + $i), $code)) {
            $found = $now + $i;
        }
    }
    return $found;
}

/** Checks a login code and refuses a code that was already used. */
function totp_verify_login(array $user, string $code): bool {
    $step = totp_match((string)$user['totp_secret'], $code);
    if ($step === null || $step <= (int)$user['totp_last_step']) {
        return false;
    }
    db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
    return true;
}

/* ---------- Recovery codes ---------- */

function normalize_recovery(string $c): string {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $c));
}

function make_recovery_codes(int $userId): array {
    $pdo = db();
    $pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
    $codes = [];
    $ins = $pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
    for ($i = 0; $i < 8; $i++) {
        $raw = strtolower(bin2hex(random_bytes(5)));
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        $ins->execute([$userId, hash('sha256', $raw)]);
    }
    return $codes;
}

function use_recovery_code(int $userId, string $code): bool {
    $norm = normalize_recovery($code);
    if (strlen($norm) !== 10) {
        return false;
    }
    $st = db()->prepare('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1');
    $st->execute([now(), $userId, hash('sha256', $norm)]);
    return $st->rowCount() === 1;
}

function finish_login(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['pw_uid'], $_SESSION['pw_time']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    clear_attempts();
}

/* ---------- Files on disk ---------- */

function upload_path(string $stored): string {
    return STORAGE_DIR . '/uploads/' . preg_replace('/[^a-f0-9]/', '', $stored);
}

function delete_stored_files(array $storedNames): void {
    foreach ($storedNames as $n) {
        $p = upload_path($n);
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/* ---------- Small key/value settings (Google Drive keys, last backup status) ---------- */

function ensure_settings_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

function setting_get(string $k, $default = null) {
    ensure_settings_table();
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $k, string $v): void {
    ensure_settings_table();
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

function setting_del(string $k): void {
    ensure_settings_table();
    db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
}

function tmp_dir(): string {
    ensure_storage();
    $d = STORAGE_DIR . '/tmp';
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    // sweep leftovers older than 2 hours
    foreach (glob($d . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }
    return $d;
}

/* ---------- Project groups (added in v1.1). Safe to run on every request: only adds, never removes. ---------- */

function ensure_groups(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS project_groups (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      color CHAR(7) NOT NULL,
      position INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'group_id'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE projects ADD COLUMN group_id INT UNSIGNED NULL DEFAULT NULL');
    }
    $done = true;
}

/* ---------- PIN lock for sections (added in v1.2). Add-only migration. ---------- */

function ensure_locks(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'locked'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE sections ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0');
    }
    $done = true;
}

const PIN_WINDOW_SECS = 300;

function pin_is_set(): bool {
    return (string)setting_get('pin_hash', '') !== '';
}

/** True while the PIN was entered in this session within the last 5 minutes. */
function pin_open(bool $extend = false): bool {
    start_session();
    $until = (int)($_SESSION['pin_until'] ?? 0);
    if ($until > time()) {
        if ($extend) {
            $_SESSION['pin_until'] = time() + PIN_WINDOW_SECS;
        }
        return true;
    }
    return false;
}

function pin_open_now(): void {
    start_session();
    $_SESSION['pin_until'] = time() + PIN_WINDOW_SECS;
}

function pin_close(): void {
    start_session();
    unset($_SESSION['pin_until']);
}

function pin_valid_format(string $pin): bool {
    return (bool)preg_match('/^\d{6}$/', $pin);
}

function pin_key(): string {
    return 'p:' . substr(client_ip(), 0, 40);
}

function pin_too_many(): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?');
    $st->execute([pin_key(), gmdate('Y-m-d H:i:s', time() - 900)]);
    return (int)$st->fetchColumn() >= 5;
}

function pin_record_fail(): void {
    db()->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)')->execute([pin_key(), now()]);
}

function pin_clear_fails(): void {
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([pin_key()]);
}

/** Checks a PIN with throttling. Returns true if right. Throws RuntimeException with a message otherwise. */
function pin_check(string $pin): bool {
    if (pin_too_many()) {
        throw new RuntimeException('Too many wrong PINs. Wait 15 minutes and try again.');
    }
    $hash = (string)setting_get('pin_hash', '');
    if ($hash === '' || !pin_valid_format($pin) || !password_verify($pin, $hash)) {
        pin_record_fail();
        throw new RuntimeException('That PIN is not right.');
    }
    pin_clear_fails();
    return true;
}
