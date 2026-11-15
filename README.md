# Project Tabs

A small, private, self-hosted notebook for keeping everything about a project in one place: links, notes, credentials, to-dos, images and documents. Runs on ordinary PHP + MySQL hosting (cPanel is fine) and installs on your phone as a PWA.

![Desktop](docs/desktop-light.png)

## How it works

- Create projects in the left list.
- Open a project and slide up the **Tabs** tray. Each tab is a coloured title (Notes, Links, To-do, anything you create).
- Drag a title into the project, or just tap it. The same title can be added as many times as you like.
- Every section is a plain-text box that grows as you type. Links become clickable.
- Attach images and documents to any section by drag and drop, paste, or the attach button.

<p><img src="docs/phone-tabs.png" width="280" alt="Phone"> <img src="docs/desktop-dark.png" width="520" alt="Dark mode"></p>

## Features

- Single user, password login with optional 2FA (any authenticator app) and recovery codes
- Reusable, recolourable tab titles; light and dark themes
- Autosave, image downscaling, lightbox viewer
- Installable on iOS and Android (PWA)
- **Export** everything as a zip (JSON + readable Markdown + your files)
- **Automatic backup to Google Drive** via a cPanel cron job, keeping the newest N backups

## Requirements

PHP 7.4+ (with PDO MySQL, ZipArchive, cURL, fileinfo), MySQL/MariaDB, HTTPS.

## Install

1. Upload the files to a folder on your host.
2. Create a MySQL database and user in cPanel.
3. Copy `config.sample.php` to `config.php` and fill in your details.
4. Open `setup.php` in your browser once, enter your setup key, and create your account.

Full steps, Google Drive setup, cron and troubleshooting are in [INSTALL.md](INSTALL.md).

## Limitations

- One user only, by design.
- Backups can be exported and stored, but there is no in-app Restore button yet.
- Plain text only (no rich formatting).

## License

MIT. See [LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
