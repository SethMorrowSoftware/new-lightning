<?php
/**
 * Example installation config.
 *
 * You do not normally write this by hand — public/setup.php generates
 * config/config.php for you. Copy this file only if you are deploying by
 * script and want to skip the wizard.
 *
 * Generate an app key with:
 *   php -r "echo 'base64:' . base64_encode(random_bytes(32)), PHP_EOL;"
 */
return [
    // 'sqlite' (default, zero setup) or 'mysql'
    'db_driver' => 'sqlite',

    // MySQL only — create the database and user in cPanel first.
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',

    // Encrypts the Slack token and SMTP password stored in the database.
    // Changing it makes existing secrets unreadable — you would re-enter them.
    'app_key' => '',

    // Public URL of the app, without a trailing slash — include the subfolder
    // if it is installed in one. Used for the "Open the dashboard" link in
    // Slack and email alerts, which are sent from cron where there is no
    // request to infer it from. It can also be set from the System settings
    // tab, which overrides this.
    'base_url' => 'https://example.com/stormwatch',

    // The URL path the app is mounted at. Normally detected automatically —
    // set it only if sign-in fails with "session expired", which means the
    // detection got it wrong for your rewrite arrangement.
    'base_path' => '',

    // Set true only when the site sits behind a proxy or CDN you control, so
    // client IPs are read from X-Forwarded-For.
    'trusted_proxy' => false,
];
