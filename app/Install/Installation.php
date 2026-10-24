<?php

namespace App\Install;

/**
 * Knows whether the panel has been installed and, before it is, keeps the app working without a
 * .env file: file sessions, a temporary encryption key and a one-time access code.
 */
class Installation
{
    public static function isInstalled(): bool
    {
        if (app()->runningUnitTests() && ! config('panel.install.enforce_in_tests')) {
            return true;
        }

        if (is_file(config('panel.install.lock_path'))) {
            return true;
        }

        // A manual install (the old README flow) has an APP_KEY in .env but never ran the wizard.
        return self::envHasKey();
    }

    public static function envHasKey(): bool
    {
        $path = config('panel.install.env_path');
        if (! is_file($path)) {
            return false;
        }

        return (bool) preg_match('/^APP_KEY=\S+/m', (string) file_get_contents($path));
    }

    /** Make the app usable before .env exists. Called only for web requests of an uninstalled panel. */
    public static function prepare(): void
    {
        $key = self::persisted('key_path', fn () => 'base64:'.base64_encode(random_bytes(32)));
        $code = self::persisted('code_path', fn () => self::newCode());

        config([
            'app.key' => $key,
            // Secrets typed into the wizard live in the session, so keep them encrypted on disk.
            'session.driver' => $key && $code ? 'file' : 'array',
            'session.encrypt' => true,
            'cache.default' => $key && $code ? 'file' : 'array',
            'queue.default' => 'sync',
        ]);

        if (! $key) {
            config(['app.key' => 'base64:'.base64_encode(hash('sha256', php_uname().base_path(), true))]);
        }
    }

    public static function accessCode(): ?string
    {
        $path = config('panel.install.code_path');

        return is_file($path) ? trim((string) file_get_contents($path)) : null;
    }

    public static function codeMatches(string $given): bool
    {
        $code = self::accessCode();
        $norm = fn (string $s) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));

        return $code !== null && $code !== '' && hash_equals($norm($code), $norm($given));
    }

    /** The key generated for the wizard, reused as APP_KEY so nothing encrypted is lost. */
    public static function temporaryKey(): ?string
    {
        $path = config('panel.install.key_path');

        return is_file($path) ? trim((string) file_get_contents($path)) : null;
    }

    public static function markInstalled(array $info = []): void
    {
        $lock = config('panel.install.lock_path');
        file_put_contents($lock, json_encode($info + ['installed_at' => now()->toIso8601String(), 'version' => config('panel.version')], JSON_PRETTY_PRINT));
        @chmod($lock, 0600);

        foreach (['code_path', 'key_path'] as $k) {
            @unlink(config("panel.install.$k"));
        }
    }

    /** Best effort: if the storage folder is not writable the requirements page says so. */
    private static function persisted(string $config, callable $make): ?string
    {
        $path = config("panel.install.$config");
        if (is_file($path) && ($v = trim((string) @file_get_contents($path))) !== '') {
            return $v;
        }

        $value = $make();
        if (@file_put_contents($path, $value."\n") === false) {
            return null;
        }
        @chmod($path, 0600);

        return $value;
    }

    private static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($chars, 4));
    }
}
