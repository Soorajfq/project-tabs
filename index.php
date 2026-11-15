<?php
require __DIR__ . '/lib.php';
security_headers();
start_session();

try {
    $user = current_user();
} catch (Throwable $e) {
    http_response_code(500);
    exit('The app is not set up yet. Open setup.php first.');
}
if (!$user) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: no-store');
$maxMb = (int)(cfg('max_upload_mb') ?: 20);
$v = '2'; // bump to refresh cached CSS/JS after an update
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<meta name="theme-color" content="#4338CA">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Project Tabs">
<title>Project Tabs</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body data-max-mb="<?= $maxMb ?>">

<div class="app" id="appView">
  <aside class="rail" id="rail" aria-label="Projects">
    <div class="rail-top">
      <div class="brand">Project Tabs</div>
      <form class="new-project" id="newProjectForm">
        <label class="sr" for="newProject">New project name</label>
        <input id="newProject" placeholder="New project" autocomplete="off" maxlength="190">
        <button type="submit">Create</button>
      </form>
    </div>
    <div class="rail-label">Projects</div>
    <ul class="plist" id="plist"></ul>
    <div class="rail-foot">
      <button class="link-btn" id="signOut" type="button">Sign out</button>
      <button class="link-btn" id="settingsBtn" type="button">Settings</button>
    </div>
  </aside>
  <div class="scrim" id="scrim"></div>

  <main class="main">
    <header class="bar">
      <button class="icon-btn" id="menuBtn" type="button" aria-label="Open projects"></button>
      <label class="sr" for="projName">Project name</label>
      <input class="proj-name" id="projName" autocomplete="off" maxlength="190" disabled>
      <span class="proj-meta" id="projMeta"></span>
      <span class="save-state" id="saveState" role="status" aria-live="polite"></span>
    </header>

    <div class="scroll" id="scroll">
      <div class="doc">
        <div id="sections"></div>
        <div class="drop-hint" id="dropHint"></div>
      </div>
    </div>

    <section class="tray open" id="tray" aria-label="Tabs tray">
      <button class="tray-handle" id="trayToggle" type="button" aria-expanded="true">
        <span class="t-label">Tabs</span>
        <span class="t-count" id="trayCount">0</span>
        <span class="t-hint">Drag a title up into the project, or tap it</span>
        <span class="t-chev" id="chev"></span>
      </button>
      <div class="tray-body">
        <div class="tray-inner">
          <div class="tray-pad">
            <div class="chips" id="trayList"></div>
            <form class="tray-form" id="trayForm">
              <label class="sr" for="trayNew">New title</label>
              <input id="trayNew" placeholder="New title, e.g. Receipts" autocomplete="off" maxlength="120">
              <button type="submit">Add title</button>
            </form>
            <p class="tray-tip">Tap a colour dot to change it. Double-click (or double-tap) a title to rename it. Titles can be added to a project as many times as you like.</p>
          </div>
        </div>
      </div>
    </section>
  </main>
</div>

<div class="palette" id="palette" hidden role="menu" aria-label="Choose a colour"></div>

<div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-label="Image viewer">
  <button class="lb-close" type="button" aria-label="Close image"><svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></button>
  <img alt="">
  <div class="lb-cap"></div>
  <a class="lb-dl" href="#" download>Download</a>
</div>

<div class="modal" id="settings" hidden role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
  <div class="modal-card">
    <div class="modal-head">
      <h2 id="settingsTitle">Settings</h2>
      <button class="icon-btn show" id="settingsClose" type="button" aria-label="Close settings"></button>
    </div>
    <div class="modal-body" id="settingsBody"></div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<script src="assets/qrcode.js?v=<?= $v ?>"></script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
