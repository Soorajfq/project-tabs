# Security

Project Tabs is a single-user, self-hosted app. If you find a vulnerability, please open a private security advisory on GitHub (Security tab > Report a vulnerability) rather than a public issue.

Hardening already in place: bcrypt passwords, optional TOTP 2FA with replay protection and one-time recovery codes, login throttling, CSRF tokens, uploads stored outside web access and served only to a signed-in user, and a deny-list in `.htaccess` for source and config files.

Keep `config.php` private, use HTTPS, and delete or lock `setup.php` after first use if you wish (it already refuses to run once a user exists).
