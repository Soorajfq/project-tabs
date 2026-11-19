<?php
// Builds a backup zip: backup.json (everything), a readable .md per project, and the attached files.
require_once __DIR__ . '/lib.php';

function bk_slug(string $s, int $max = 60): string {
    $s = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s), " ._-");
    if ($s === '') {
        $s = 'untitled';
    }
    return mb_substr($s, 0, $max, 'UTF-8');
}

function bk_filename(string $name): string {
    $ext = '';
    if (preg_match('/(\.[A-Za-z0-9]{1,8})$/', $name, $m)) {
        $ext = strtolower($m[1]);
        $name = substr($name, 0, -strlen($m[1]));
    }
    return bk_slug($name, 80) . $ext;
}

/** Writes the backup zip to $dest and returns counts. Passwords and 2FA secrets are never included. */
function build_backup_zip(string $dest): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP "zip" extension is switched off on this server. In cPanel open Select PHP Version > Extensions and tick "zip".');
    }
    $pdo = db();
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the backup file. Check that the storage folder is writable.');
    }

    $tray = $pdo->query('SELECT id, title, color, position FROM tray_titles ORDER BY position, id')->fetchAll();
    ensure_groups();
    $projects = $pdo->query('SELECT * FROM projects ORDER BY id')->fetchAll();
    $groups = $pdo->query('SELECT id, name, color, position FROM project_groups ORDER BY position, id')->fetchAll();
    $groupName = [];
    foreach ($groups as $g) {
        $groupName[(int)$g['id']] = $g['name'];
    }

    $secByProject = [];
    foreach ($pdo->query('SELECT * FROM sections ORDER BY project_id, position, id')->fetchAll() as $s) {
        $secByProject[(int)$s['project_id']][] = $s;
    }
    $filesBySection = [];
    foreach ($pdo->query('SELECT * FROM files ORDER BY section_id, id')->fetchAll() as $f) {
        $filesBySection[(int)$f['section_id']][] = $f;
    }

    $out = [
        'format' => 'project-tabs-backup',
        'version' => 1,
        'created_at' => gmdate('c'),
        'tray' => $tray,
        'groups' => $groups,
        'projects' => [],
    ];
    $nSections = 0;
    $nFiles = 0;

    foreach ($projects as $p) {
        $pid = (int)$p['id'];
        $folder = 'projects/' . sprintf('%03d', $pid) . '-' . bk_slug($p['name'], 50);
        $md = '# ' . $p['name'] . "\n\n";
        $pj = [
            'id' => $pid, 'name' => $p['name'], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
            'folder' => $folder, 'group' => $p['group_id'] !== null ? ($groupName[(int)$p['group_id']] ?? null) : null,
            'group_id' => $p['group_id'] !== null ? (int)$p['group_id'] : null, 'sections' => [],
        ];
        foreach ($secByProject[$pid] ?? [] as $s) {
            $sid = (int)$s['id'];
            $nSections++;
            $md .= '## ' . $s['title'] . "\n\n";
            if (trim((string)$s['body']) !== '') {
                $md .= rtrim($s['body']) . "\n\n";
            }
            $sj = [
                'id' => $sid, 'title' => $s['title'], 'color' => $s['color'], 'body' => $s['body'],
                'position' => (int)$s['position'], 'created_at' => $s['created_at'], 'updated_at' => $s['updated_at'],
                'files' => [],
            ];
            $links = [];
            foreach ($filesBySection[$sid] ?? [] as $f) {
                $path = upload_path($f['stored_name']);
                $rel = 'files/' . (int)$f['id'] . '-' . bk_filename($f['original_name']);
                $fj = [
                    'id' => (int)$f['id'], 'name' => $f['original_name'], 'mime' => $f['mime'],
                    'size' => (int)$f['size'], 'is_image' => (bool)$f['is_image'], 'path' => $folder . '/' . $rel,
                ];
                if (is_file($path)) {
                    $zip->addFile($path, $folder . '/' . $rel);
                    $nFiles++;
                    $links[] = '- [' . $f['original_name'] . '](' . str_replace(' ', '%20', $rel) . ')';
                } else {
                    $fj['missing'] = true;
                    $links[] = '- ' . $f['original_name'] . ' (the file was missing on the server)';
                }
                $sj['files'][] = $fj;
            }
            if ($links) {
                $md .= "Attachments:\n" . implode("\n", $links) . "\n\n";
            }
            $pj['sections'][] = $sj;
        }
        $zip->addFromString($folder . '/' . bk_slug($p['name'], 50) . '.md', $md);
        $out['projects'][] = $pj;
    }

    $zip->addFromString('backup.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    $zip->addFromString('README.txt',
        "Project Tabs backup, made " . gmdate('Y-m-d H:i') . " UTC\n\n" .
        "projects/  One folder per project. Open the .md file inside to read your sections as plain text.\n" .
        "           Attached images and documents are in the files/ folder next to it.\n" .
        "backup.json  Everything in one machine-readable file (projects, sections, titles, colours, file list).\n\n" .
        "Your password and two-factor secret are not part of this backup.\n");
    if (!$zip->close()) {
        throw new RuntimeException('Could not finish writing the backup file. The server may be out of disk space.');
    }
    return [
        'projects' => count($projects),
        'sections' => $nSections,
        'files' => $nFiles,
        'bytes' => (int)@filesize($dest),
    ];
}

function backup_filename(): string {
    return 'project-tabs-backup-' . date('Ymd-His') . '.zip';
}
