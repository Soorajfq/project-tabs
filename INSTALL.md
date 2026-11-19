# Project Tabs: install on cPanel

Takes about 10 minutes. You need: cPanel hosting with PHP 7.4 or newer (8.x is fine) and MySQL. The PHP extensions `zip` and `curl` should be on (they are on most hosts; cPanel > Select PHP Version > Extensions).

## 1. Create the database
cPanel > **MySQL Database Wizard**
1. Create a database (for example `projecttabs`).
2. Create a database user with a strong password.
3. Tick **All Privileges** and finish.

cPanel adds your account name in front, so the real names look like `myacct_projecttabs` and `myacct_ptuser`. Write down all three values and the password.

## 2. Upload the files
1. cPanel > **File Manager**. Open `public_html` (or a subdomain folder) and create a folder, for example `tabs`.
2. Open that folder, click **Upload**, choose `project-tabs.zip`, then right-click the zip > **Extract**.
3. Check that `index.php`, `login.php` and `config.php` sit directly inside the `tabs` folder (not inside another folder).
4. Turn on **Settings > Show Hidden Files** in File Manager so you can see `.htaccess` and `.user.ini`. Leave them in place.

## 3. Create config.php
In File Manager, right-click `config.sample.php` > **Copy**, and name the copy `config.php`. Then right-click `config.php` > **Edit** and fill in:
- `db_name`, `db_user`, `db_pass` from step 1 (`db_host` stays `localhost`)
- `setup_key`: any long random text, for example 30 mixed letters and numbers. You type it once on the setup page.

## 4. Use HTTPS
cPanel > **SSL/TLS Status** > run **AutoSSL** for your domain. The app handles passwords, so open it with `https://` only.  The included `.htaccess` redirects http to https automatically once the certificate is active.

## 5. Create your login
1. Open `https://yourdomain.com/tabs/setup.php`
2. Enter the setup key, then choose your username and password (10+ characters).
3. **Delete `setup.php`** from File Manager when it says your login is ready.

## 6. Turn on two-factor sign-in (recommended)
1. Sign in, tap **Settings**, then **Set up** under Two-factor sign-in.
2. Scan the QR code with Google Authenticator, Authy or 1Password, and type the 6-digit code it shows.
3. **Save the 8 recovery codes** somewhere safe (password manager). Each works once if you lose your phone.

## 7. Put it on your phone
- **iPhone:** open the site in Safari > Share > **Add to Home Screen**.
- **Android:** open it in Chrome > menu > **Install app**.

## Quick safety check
Open these in your browser. Each should show **403 Forbidden** (or not found), never the file contents:
- `https://yourdomain.com/tabs/config.php`
- `https://yourdomain.com/tabs/storage/error.log`
- `https://yourdomain.com/tabs/schema.sql`

If any of them shows content, your host is ignoring `.htaccess`. Ask your host to enable `AllowOverride`, or move the folder to a host that does.

## Upload size
Files up to 20 MB are allowed (change `max_upload_mb` in config.php). Your PHP must allow at least that much. This package includes a `.user.ini` that asks for 25 MB. If uploads say "bigger than the PHP upload limit", go to cPanel > **MultiPHP INI Editor** and set `upload_max_filesize` to 25M and `post_max_size` to 30M. Photos are shrunk in the browser before upload, so most are far smaller than the limit.

## Backups
Your data lives in two places: the database and the `storage/uploads` folder. The app can back up both for you.

### A. Download a backup any time (no setup)
Tap **Settings > Download backup**. You get one zip with:
- `projects/`: a folder per project with a plain-text `.md` file you can read anywhere, plus the attached images and documents
- `backup.json`: everything in one file (for restoring later)

On an iPhone or Android phone the share sheet opens, so you can save the zip straight to **iCloud Drive**, **Google Drive** or Files. Your password and 2FA secret are never included.

### B. Automatic nightly backup to Google Drive
Every night the server sends a fresh zip to a folder called **Project Tabs backups** in your Google Drive and keeps the newest 14. The setup is done once and takes about 10 minutes. It is free. The app only gets permission to see files it creates itself, not the rest of your Drive.

1. **Create a Google Cloud project.** Go to console.cloud.google.com, sign in, and create a project (any name, for example "Project Tabs").
2. **Turn on the Drive API.** Open APIs & Services > Library, search for **Google Drive API**, and click Enable.
3. **Set up the consent screen** (it may be called "Google Auth Platform" or "OAuth consent screen"):
   - App name: Project Tabs. Support email: yours. Audience / user type: **External**.
   - Add the scope `https://www.googleapis.com/auth/drive.file` (under Data Access or Scopes).
   - **Publish the app** so its status is **In production**. If you leave it in "Testing", Google ends the connection every 7 days.
   - If Google later says the app is not verified, choose **Advanced** and continue. It is your own app and only you use it.
4. **Create the keys.** Credentials > Create credentials > **OAuth client ID** > Application type **Web application**. Under *Authorised redirect URIs* paste the address shown in Settings under "Automatic backup to Google Drive" (it looks like `https://yourdomain.com/tabs/gdrive.php`). Create it, then copy the **Client ID** and **Client secret**.
5. **Connect.** In the app open Settings, paste the two keys, tap **Save keys**, then **Connect Google Drive**, choose your Google account and allow. Tap **Back up now** and check that the folder appears in your Drive.
6. **Make it automatic.** In cPanel open **Cron Jobs**, choose **Once Per Day**, and for the command paste the one shown in Settings (it looks like `/usr/local/bin/php /home/youraccount/public_html/tabs/cron_backup.php`). Add ` > /dev/null` at the end so cPanel emails you only when a backup fails. If the PHP path is wrong for your host, use the PHP path your host documents for cron jobs.

If Google ever ends the connection, Settings shows the reason with a **Connect Google Drive** button, and the cron job emails you the error.

To keep more or fewer than 14 backups, add `'backup_keep' => 30,` (or any number) to config.php.

### Restoring
The zip contains everything, but there is no Restore button in the app yet. If you ever need one, ask for it to be added. Until then the `.md` files and attached files in the zip can be read and copied by hand.

### Also back up the server
cPanel > **Backup** or your host's own backups cover the server itself, which is worth having as well.

## Updating later
Upload the new zip and extract it over the old files. The zip does not contain `config.php` or any of your data, so your settings, database and uploaded files stay as they are. (It does bring back `setup.php`; delete it again, or leave it, since it refuses to run once your login exists.) The database additions (backup settings in 1.0, project groups in 1.1) are added only, never removed: they are created automatically the first time they are needed, so your projects and files are untouched. The one new database table (for backup settings) is created automatically the first time it is needed. Phones pick up new CSS and JS by themselves because `index.php` carries a version number.

## If something goes wrong
- **Blank page or error 500:** open `storage/error.log` in File Manager, and check that PHP is 7.4 or newer (cPanel > MultiPHP Manager).
- **"Could not connect to the database":** recheck the three database values and password in config.php.
- **Locked out after typing wrong passwords:** wait 15 minutes. Sign-in is limited to 8 failed tries per 15 minutes.
- **Lost your phone and your recovery codes:** phpMyAdmin > table `users` > edit your row and set `totp_enabled` to `0`. Then sign in with your password and set up two-factor again.
- **Forgot your password:** in cPanel > **Terminal** run `php -r 'echo password_hash("NEW-PASSWORD-HERE", PASSWORD_DEFAULT);'`, then paste the result into `password_hash` on your row in phpMyAdmin > `users`.

## What is where
- `index.php`, `assets/` : the app and its look
- `api.php`, `file.php`, `export.php` : data, file access and the backup download (all need you signed in)
- `gdrive.php`, `cron_backup.php`, `lib_backup.php`, `lib_drive.php` : Google Drive backup
- `login.php`, `setup.php`, `lib.php` : sign-in, first-time setup, shared code
- `config.php` : your own settings (you create it from `config.sample.php`; never inside the download)
- `storage/` : your uploaded files and sessions (blocked from the web)
- `schema.sql` : the database layout, applied automatically by setup.php
