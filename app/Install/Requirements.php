<?php

namespace App\Install;

use Illuminate\Http\Request;

/** What the server must offer. Required checks block the install; the others are advice. */
class Requirements
{
    /**
     * @return array{checks: list<array{label: string, ok: bool, required: bool, hint: string}>, ok: bool}
     */
    public static function check(?Request $request = null): array
    {
        $checks = [];
        $add = function (string $label, bool $ok, bool $required, string $hint = '') use (&$checks) {
            $checks[] = ['label' => $label, 'ok' => $ok, 'required' => $required, 'hint' => $ok ? '' : $hint];
        };

        $add('PHP '.PHP_VERSION.' (8.2 or newer)', version_compare(PHP_VERSION, '8.2.0', '>='), true, 'Choose PHP 8.2+ in cPanel, Select PHP Version.');

        $branch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if ($minimum = config("panel.php_security_minimums.$branch")) {
            $add("PHP is on a security-patched release ($minimum or newer)", version_compare(PHP_VERSION, $minimum, '>='), false, "This PHP has known critical security flaws fixed in $minimum. Update PHP (cPanel, Select PHP Version) or ask your host to; until then keep the panel behind HTTPS and do not expose it more widely than needed.");
        }

        foreach (['curl', 'mbstring', 'openssl', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'json', 'pdo'] as $ext) {
            $add("PHP extension: $ext", extension_loaded($ext), true, "Enable $ext in cPanel, Select PHP Version, Extensions.");
        }
        $add('Database driver: pdo_mysql or pdo_sqlite', extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'), true, 'Enable pdo_mysql (or pdo_sqlite) in Select PHP Version.');
        $add('PHP extension: zip (for CSV and backup tools)', extension_loaded('zip'), false, 'Optional. Enable zip in Select PHP Version.');

        $env = config('panel.install.env_path');
        foreach ([
            'storage/app' => storage_path('app'),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ] as $label => $path) {
            $add("Writable: $label", is_dir($path) && is_writable($path), true, "chmod 755 (or 775) $label so PHP can write to it.");
        }
        $add('Can create .env', is_file($env) ? is_writable($env) : is_writable(dirname($env)), true, 'Make the project folder writable, or create an empty .env file in it.');
        $add('.env.example is present', is_file(config('panel.install.env_template')), true, 'Upload .env.example (a hidden file) next to artisan.');

        if ($request) {
            $secure = $request->isSecure() || in_array($request->getHost(), ['localhost', '127.0.0.1'], true);
            $add('Served over HTTPS', $secure, false, 'Google sign-in only accepts https:// redirect URIs. Enable AutoSSL for this domain first.');
            $add('Document root is the public folder', ! str_contains($request->getBaseUrl().$request->getPathInfo(), '/public'), false, 'In cPanel set the domain\'s document root to the project\'s public folder, so .env and storage are never web-reachable.');
        }

        return ['checks' => $checks, 'ok' => ! collect($checks)->contains(fn ($c) => $c['required'] && ! $c['ok'])];
    }
}
