<?php

return [
    'host'       => env('CPANEL_HOST'),
    'port'       => (int) env('CPANEL_PORT', 2083),
    'user'       => env('CPANEL_USER'),
    'token'      => env('CPANEL_TOKEN'),
    'verify_ssl' => (bool) env('CPANEL_VERIFY_SSL', true),
    'timeout'    => (int) env('CPANEL_TIMEOUT', 30),

    // Webmail shortcut. {host} is replaced with CPANEL_HOST.
    'webmail_url' => env('CPANEL_WEBMAIL_URL', 'https://{host}:2096'),

    // Limits applied to input validation.
    'max_quota_mb'   => (int) env('PANEL_MAX_QUOTA_MB', 20480),
    'csv_max_rows'   => (int) env('PANEL_CSV_MAX_ROWS', 500),
    'csv_max_kb'     => (int) env('PANEL_CSV_MAX_KB', 512),
    'quota_warn_pct' => 80,
    'bulk_per_run'   => (int) env('PANEL_BULK_PER_RUN', 40),

    // Client settings shown to new users.
    'client' => [
        'imap_port'          => 993,
        'pop_port'           => 995,
        'smtp_port'          => 465,
        'smtp_starttls_port' => 587,
    ],
];
