<?php

return [
    'version' => '1.1.0',

    // Oldest PHP release per branch that includes the 2026-10-22 security fixes (critical remote code execution,
    // php.net ChangeLog). The installer warns, without blocking, when the server is behind. Raise this list
    // when newer security releases come out. Distribution packages may carry the fix under an older number.
    'php_security_minimums' => ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'],

    'security' => [
        // A session ends this long after sign-in however active it is (the idle limit is SESSION_LIFETIME).
        'session_absolute_minutes' => (int) env('PANEL_SESSION_ABSOLUTE_MINUTES', 720),
        // Changes (POST/PUT/DELETE) one signed-in person may make per minute; slows a hijacked session.
        'writes_per_minute' => (int) env('PANEL_WRITES_PER_MINUTE', 60),
        // Roles that must have a second factor (authenticator app or passkey) before they can use the panel.
        'two_factor_required_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('PANEL_2FA_REQUIRED_ROLES', 'super_admin'))))),
        // Sensitive changes need a confirmation this recent. 0 turns the check off.
        'step_up_minutes' => (int) env('PANEL_STEP_UP_MINUTES', 10),
        // After Google accepts someone, they have this long to finish the second step.
        'two_factor_pending_minutes' => 5,
        // Email the super admins about security-relevant events (queued; needs working MAIL_ settings).
        'alerts' => filter_var(env('PANEL_SECURITY_ALERTS', true), FILTER_VALIDATE_BOOLEAN),
        // Sign-ins from an address not seen for this person in this many days raise an alert to them.
        'new_ip_days' => 90,
        // This many denied sign-ins within 10 minutes raise one alert.
        'failed_login_burst' => 5,
        // Removing a domain or a super admin needs a second super admin to approve (only when two or more exist).
        'four_eyes' => filter_var(env('PANEL_FOUR_EYES', true), FILTER_VALIDATE_BOOLEAN),
        'four_eyes_hours' => 48,
        // Published at /.well-known/security.txt: where people should report a vulnerability (mailto: or https: URL).
        'contact' => env('PANEL_SECURITY_CONTACT'),
        // Date the cPanel API token was created (YYYY-MM-DD). The dashboard warns when it gets old.
        'token_created' => env('CPANEL_TOKEN_CREATED'),
        // Wrong second-factor attempts allowed per person within the lockout window.
        'two_factor_max_failures' => 5,
        'two_factor_lockout_minutes' => 10,
        // All addresses together: this many wrong answers per person in an hour locks the step for that hour.
        'two_factor_total_failures' => 25,
    ],

    'audit' => [
        // Newest audit row is noted here so rows cut off the end of the log are noticed. Empty = off.
        'head_path' => env('PANEL_AUDIT_HEAD_PATH', storage_path('app/audit-head.json')),
    ],

    // Appearance. Only these values are ever accepted from a form; nothing is stored as raw CSS.
    'theme' => [
        'default' => ['name' => null, 'primary' => '#2455d6', 'header' => '#141b2d', 'font' => 'system', 'allow_palettes' => false],
        'modes' => ['system', 'light', 'dark'],
        'densities' => ['comfortable', 'compact'],
        'fonts' => [
            'system' => ['label' => 'System default', 'stack' => "system-ui,-apple-system,'Segoe UI',Roboto,sans-serif"],
            'humanist' => ['label' => 'Humanist', 'stack' => "'Segoe UI','Trebuchet MS',Candara,Optima,Verdana,sans-serif"],
            'serif' => ['label' => 'Serif', 'stack' => "Georgia,'Times New Roman',serif"],
            'mono' => ['label' => 'Monospace', 'stack' => "ui-monospace,Consolas,'Courier New',monospace"],
        ],
        // Palettes a person may pick when the super admin allows it. Colours are accent colours only.
        'palettes' => [
            'ocean' => ['label' => 'Ocean', 'primary' => '#0b6e99'],
            'forest' => ['label' => 'Forest', 'primary' => '#2d7a3e'],
            'plum' => ['label' => 'Plum', 'primary' => '#7a3d8f'],
            'graphite' => ['label' => 'Graphite', 'primary' => '#4a5568'],
        ],
        'logo_max_bytes' => 1024 * 1024,
        'logo_max_pixels' => 3000,
    ],

    // First-run installer. Paths are configurable so tests never touch the real files.
    'install' => [
        // Written when the installer finishes. While it exists /install answers 404.
        'lock_path' => storage_path('app/installed.lock'),
        // The configuration file the installer creates from .env.example.
        'env_path' => base_path('.env'),
        'env_template' => base_path('.env.example'),
        // One-time access code and the temporary encryption key used until .env exists.
        'code_path' => storage_path('app/install-code.txt'),
        'key_path' => storage_path('app/install-key.txt'),
        // Where a SQLite install keeps its database.
        'sqlite_path' => database_path('database.sqlite'),
        // Unit tests count as "installed" so the whole suite is not redirected; the installer tests turn this on.
        'enforce_in_tests' => false,
    ],
];
