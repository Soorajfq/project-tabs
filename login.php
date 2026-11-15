<?php
require __DIR__ . '/lib.php';
security_headers();
start_session();

try {
    if (current_user()) {
        header('Location: index.php');
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('The app is not set up yet. Open setup.php first.');
}

$error = '';
$step = 'password';

// Step 2 is available for 5 minutes after the password was accepted.
if (!empty($_SESSION['pw_uid']) && !empty($_SESSION['pw_time']) && time() - (int)$_SESSION['pw_time'] < 300) {
    $step = 'code';
} else {
    unset($_SESSION['pw_uid'], $_SESSION['pw_time']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = (string)($_POST['step'] ?? '');
    if (!csrf_ok((string)($_POST['csrf'] ?? ''))) {
        $error = 'The page expired. Please try again.';
    } elseif (too_many_attempts()) {
        $error = 'Too many failed attempts. Wait 15 minutes and try again.';
    } elseif ($which === 'password') {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([trim((string)($_POST['username'] ?? ''))]);
        $u = $st->fetch();
        // Always run a hash check so a wrong username takes as long as a wrong password.
        $hash = $u ? $u['password_hash'] : '$2y$10$4p2TqDClcRcA46eLMW7ZYOdeNQGjpHBcQIJwPt3WQZVosmN1bTAD6';
        $ok = password_verify((string)($_POST['password'] ?? ''), $hash) && $u;
        if (!$ok) {
            record_failed_attempt();
            $error = 'That username or password is not right.';
        } elseif ((int)$u['totp_enabled'] === 1) {
            session_regenerate_id(true);
            $_SESSION['pw_uid'] = (int)$u['id'];
            $_SESSION['pw_time'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: login.php');
            exit;
        } else {
            finish_login((int)$u['id']);
            header('Location: index.php');
            exit;
        }
    } elseif ($which === 'code' && $step === 'code') {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['pw_uid']]);
        $u = $st->fetch();
        $code = trim((string)($_POST['code'] ?? ''));
        $ok = false;
        if ($u) {
            $ok = preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code))
                ? totp_verify_login($u, $code)
                : use_recovery_code((int)$u['id'], $code);
        }
        if ($ok) {
            finish_login((int)$u['id']);
            header('Location: index.php');
            exit;
        }
        record_failed_attempt();
        $error = 'That code is not right. Codes change every 30 seconds.';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#4338CA">
<title>Sign in</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login">
  <div class="login-card">
    <div class="login-brand">
      <div class="demo-chips" aria-hidden="true">
        <span style="--c:#1D4ED8">Links</span><span style="--c:#A16207">Notes</span><span style="--c:#7E22CE">Credentials</span><span style="--c:#15803D">To-do</span>
      </div>
      <h1>Project Tabs</h1>
      <p>Keep every link, note and file for a project in one place.</p>
    </div>

<?php if ($step === 'password'): ?>
    <form class="form" method="post" action="login.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="step" value="password">
      <div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" autocapitalize="none" required autofocus></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
      <p class="err" role="alert"><?= h($error) ?></p>
      <button class="btn" type="submit">Sign in</button>
    </form>
<?php else: ?>
    <form class="form" method="post" action="login.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="step" value="code">
      <div class="field">
        <label for="code">Authenticator code or recovery code</label>
        <input id="code" name="code" inputmode="text" autocomplete="one-time-code" maxlength="16" placeholder="123456" required autofocus>
      </div>
      <p class="err" role="alert"><?= h($error) ?></p>
      <button class="btn" type="submit">Verify</button>
      <p class="note">Enter the 6-digit code from your authenticator app. If you lost your phone, enter one of your recovery codes instead (like a1b2c-3d4e5).</p>
    </form>
<?php endif; ?>
  </div>
</div>
</body>
</html>
