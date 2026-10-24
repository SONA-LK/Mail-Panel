<?php

return [
    'version' => '1.0.0',

    // Oldest PHP release per branch that includes the 2026-10-22 security fixes (critical remote code execution,
    // php.net ChangeLog). The installer warns, without blocking, when the server is behind. Raise this list
    // when newer security releases come out. Distribution packages may carry the fix under an older number.
    'php_security_minimums' => ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'],

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
