<?php
// One-time setup: creates the tables and your login. Delete this file when you are done.
require __DIR__ . '/lib.php';
security_headers();
start_session();
ensure_storage();

$error = '';
$done = false;
$stage = 'form';
$cfg = cfg();

$keyIsDefault = ($cfg['setup_key'] ?? '') === '' || ($cfg['setup_key'] ?? '') === 'CHANGE-ME';
$dbIsDefault = ($cfg['db_pass'] ?? '') === 'CHANGE-ME';

try {
    if ($dbIsDefault) {
        throw new RuntimeException('config.php still has the placeholder database password. Edit config.php first.');
    }
    $pdo = db();
    $hasUsers = false;
    try {
        $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $hasUsers = false; // tables not created yet
    }
} catch (Throwable $e) {
    $stage = 'dberror';
    $error = $e instanceof RuntimeException ? $e->getMessage()
        : 'Could not connect to the database. Check db_host, db_name, db_user and db_pass in config.php. (' . $e->getMessage() . ')';
    $hasUsers = false;
}

if ($stage === 'form' && $hasUsers) {
    $stage = 'already';
}

if ($stage === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $key = (string)($_POST['setup_key'] ?? '');

    if (!csrf_ok((string)($_POST['csrf'] ?? ''))) {
        $error = 'The page expired. Reload and try again.';
    } elseif ($keyIsDefault) {
        $error = 'Set a long random setup_key in config.php first, then reload this page.';
    } elseif (!hash_equals((string)$cfg['setup_key'], $key)) {
        $error = 'The setup key does not match the one in config.php.';
    } elseif (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $user)) {
        $error = 'Username must be 3 to 64 characters: letters, numbers, and . _ @ -';
    } elseif (strlen($pass) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $sql = file_get_contents(APP_DIR . '/schema.sql');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), now()]);
            $defaults = [['Links', '#1D4ED8'], ['Notes', '#A16207'], ['Credentials', '#7E22CE'],
                         ['To-do', '#15803D'], ['Contacts', '#0F766E'], ['Ideas', '#BE185D']];
            $ins = $pdo->prepare('INSERT INTO tray_titles (title, color, position) VALUES (?, ?, ?)');
            foreach ($defaults as $i => $d) {
                $ins->execute([$d[0], $d[1], $i]);
            }
            $stage = 'done';
        } catch (Throwable $e) {
            error_log('setup: ' . $e->getMessage());
            $error = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Set up Project Tabs</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login">
  <div class="login-card">
    <div class="login-brand">
      <h1>Set up Project Tabs</h1>
    </div>
<?php if ($stage === 'dberror'): ?>
    <p class="err"><?= h($error) ?></p>
    <p class="note">Fix config.php, then reload this page.</p>

<?php elseif ($stage === 'already'): ?>
    <p>Setup is already finished. Delete <strong>setup.php</strong> from your server, then <a href="login.php">sign in</a>.</p>

<?php elseif ($stage === 'done'): ?>
    <p>Your login is ready.</p>
    <p><strong>Now delete setup.php</strong> from your server (cPanel File Manager), then sign in.</p>
    <a class="btn" style="display:grid;place-items:center;text-decoration:none" href="login.php">Go to sign in</a>

<?php else: ?>
    <?php if ($keyIsDefault): ?>
      <p class="err">Open config.php and replace the setup_key with a long random text, then reload this page.</p>
    <?php endif; ?>
    <form class="form" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="field"><label for="setup_key">Setup key (from config.php)</label><input id="setup_key" name="setup_key" type="password" required></div>
      <div class="field"><label for="username">Choose a username</label><input id="username" name="username" value="<?= h($_POST['username'] ?? '') ?>" required autocapitalize="none"></div>
      <div class="field"><label for="password">Choose a password (10+ characters)</label><input id="password" name="password" type="password" minlength="10" required></div>
      <div class="field"><label for="password2">Repeat password</label><input id="password2" name="password2" type="password" minlength="10" required></div>
      <p class="err"><?= h($error) ?></p>
      <button class="btn" type="submit">Create my login</button>
    </form>
<?php endif; ?>
  </div>
</div>
</body>
</html>
