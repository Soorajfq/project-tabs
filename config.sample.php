<?php
// Project Tabs settings.
// FIRST INSTALL: copy this file to a new file named config.php, fill in the values, then open setup.php.
// This sample is the only settings file in the download, so unzipping an update never overwrites your real config.php.
return [
    // From cPanel > MySQL Databases
    'db_host' => 'localhost',
    'db_name' => 'CPANELUSER_projecttabs',
    'db_user' => 'CPANELUSER_ptuser',
    'db_pass' => 'CHANGE-ME',

    // Any long random text. You type it once on the setup page to prove it is you.
    'setup_key' => 'CHANGE-ME',

    'timezone'      => 'Asia/Singapore',
    'backup_keep'   => 14,   // how many Google Drive backups to keep
    'max_upload_mb' => 20,   // per file. Your host's PHP limit must be at least this (see INSTALL.md)
];
