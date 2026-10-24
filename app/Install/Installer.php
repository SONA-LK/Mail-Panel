<?php

namespace App\Install;

use App\Services\CpanelClient;
use App\Services\CpanelException;
use App\Services\DomainImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Does the actual installation. Used by the web wizard and by `php artisan panel:install`.
 *
 * Everything that can fail (database, migrations, admin, domain import) runs first with settings
 * applied in memory; .env and the lock file are written last, so a failed run can simply be repeated.
 */
class Installer
{
    /** @param array{driver: string, host?: string, port?: int|string, database?: string, username?: string, password?: string} $db */
    public function testDatabase(array $db): void
    {
        $config = $this->connection($db);
        if ($config['driver'] === 'sqlite' && ! is_file($config['database'])) {
            if (! is_writable(dirname($config['database']))) {
                throw new RuntimeException('The database folder is not writable: '.dirname($config['database']));
            }
        } else {
            config(['database.connections.installer_test' => $config]);
            try {
                DB::connection('installer_test')->getPdo();
            } catch (Throwable $e) {
                throw new RuntimeException('Could not connect: '.$this->firstLine($e->getMessage()));
            } finally {
                DB::purge('installer_test');
            }
        }
    }

    /**
     * Prove the cPanel details work. Returns what the account can see.
     *
     * @param  array{host: string, port?: int|string, user: string, token: string, verify_ssl?: bool}  $cp
     * @return array{mailboxes: int, domains: int}
     */
    public function testCpanel(array $cp): array
    {
        $this->applyCpanel($cp);
        $client = app(CpanelClient::class);

        try {
            $mailboxes = $client->call('Email', 'list_pops') ?? [];
            $domains = $client->call('Email', 'list_mail_domains') ?? [];
        } catch (CpanelException $e) {
            throw new RuntimeException($e->getMessage());
        }

        return ['mailboxes' => count($mailboxes), 'domains' => count($domains)];
    }

    /**
     * @param  array{
     *   app_name?: string, url: string,
     *   db: array, cpanel: array,
     *   google: array{id: string, secret: string, allowed_domain?: string},
     *   admin: array{email: string, name?: string},
     *   import_domains?: bool, app_key?: string
     * }  $c
     * @return array{admin: string, imported: int, warnings: list<string>, login_url: string, cron: string}
     */
    public function install(array $c): array
    {
        $warnings = [];
        $previous = config('database.default');

        try {
            $this->useDatabase($c['db']);
            $this->applyCpanel($c['cpanel']);

            Artisan::call('migrate', ['--force' => true, '--database' => 'installer']);

            $admin = strtolower(trim($c['admin']['email']));
            Artisan::call('panel:make-admin', ['email' => $admin, 'name' => $c['admin']['name'] ?? null]);

            $imported = 0;
            if ($c['import_domains'] ?? true) {
                config(['queue.default' => 'database']); // the syncs are run by the cron job, not now
                try {
                    $importer = app(DomainImporter::class);
                    $imported = $importer->import(null, $importer->available())->count();
                } catch (CpanelException $e) {
                    $warnings[] = 'Domains could not be imported yet ('.$e->getMessage().'). Use Domains, Import from cPanel later.';
                }
            }
        } finally {
            config(['database.default' => $previous]);
            DB::purge('installer');
        }

        $url = rtrim($c['url'], '/');
        EnvWriter::write(config('panel.install.env_path'), config('panel.install.env_template'), $this->envValues($c, $url));

        try {
            Artisan::call('config:clear');
        } catch (Throwable) {
            // No cached config to clear.
        }

        Installation::markInstalled(['admin' => $admin, 'domains_imported' => $imported]);

        return [
            'admin' => $admin,
            'imported' => $imported,
            'warnings' => $warnings,
            'login_url' => $url.'/login',
            'cron' => '* * * * * cd '.base_path().' && php artisan schedule:run >/dev/null 2>>storage/logs/cron-errors.log',
        ];
    }

    /** @return array<string, string|int|bool|null> */
    private function envValues(array $c, string $url): array
    {
        $https = str_starts_with($url, 'https://');
        $db = $c['db'];
        $driver = $db['driver'] === 'sqlite' ? 'sqlite' : 'mysql';

        $values = [
            'APP_NAME' => $c['app_name'] ?? 'Mail Panel',
            'APP_ENV' => $https ? 'production' : 'local',
            'APP_KEY' => $c['app_key'] ?? Installation::temporaryKey() ?? 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => false,
            'APP_URL' => $url,
            'DB_CONNECTION' => $driver,
            'SESSION_SECURE_COOKIE' => $https,
            // Welcome mails are optional; the log mailer keeps the panel working until SMTP is set up.
            'MAIL_MAILER' => 'log',
            'CPANEL_HOST' => self::bareHost($c['cpanel']['host']),
            'CPANEL_PORT' => (int) ($c['cpanel']['port'] ?? 2083),
            'CPANEL_USER' => $c['cpanel']['user'],
            'CPANEL_TOKEN' => $c['cpanel']['token'],
            'CPANEL_VERIFY_SSL' => (bool) ($c['cpanel']['verify_ssl'] ?? true),
            'GOOGLE_CLIENT_ID' => $c['google']['id'],
            'GOOGLE_CLIENT_SECRET' => $c['google']['secret'],
            'GOOGLE_ALLOWED_DOMAIN' => $c['google']['allowed_domain'] ?? '',
        ];

        if ($driver === 'sqlite') {
            $values['DB_DATABASE'] = $this->connection($db)['database'];
            // Do not leave the template's MySQL placeholders behind.
            $values += ['DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => ''];
        } else {
            $values += [
                'DB_HOST' => $db['host'] ?? 'localhost',
                'DB_PORT' => (int) ($db['port'] ?? 3306),
                'DB_DATABASE' => $db['database'],
                'DB_USERNAME' => $db['username'],
                'DB_PASSWORD' => $db['password'] ?? '',
            ];
        }

        return $values;
    }

    private function useDatabase(array $db): void
    {
        $config = $this->connection($db);
        if ($config['driver'] === 'sqlite' && ! is_file($config['database'])) {
            if (@touch($config['database']) === false) {
                throw new RuntimeException('Could not create the database file '.$config['database']);
            }
        }

        config(['database.connections.installer' => $config, 'database.default' => 'installer']);
        DB::purge('installer');
    }

    private function applyCpanel(array $cp): void
    {
        config([
            'cpanel.host' => trim($cp['host']),
            'cpanel.port' => (int) ($cp['port'] ?? 2083),
            'cpanel.user' => trim($cp['user']),
            'cpanel.token' => trim($cp['token']),
            'cpanel.verify_ssl' => (bool) ($cp['verify_ssl'] ?? true),
        ]);
    }

    private function connection(array $db): array
    {
        if (($db['driver'] ?? 'mysql') === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => config('panel.install.sqlite_path'), 'prefix' => '', 'foreign_key_constraints' => true];
        }

        return [
            'driver' => 'mysql',
            'host' => $db['host'] ?? 'localhost',
            'port' => (int) ($db['port'] ?? 3306),
            'database' => $db['database'] ?? '',
            'username' => $db['username'] ?? '',
            'password' => $db['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];
    }

    /** https://server.example.com:2083/ becomes server.example.com. */
    private static function bareHost(string $host): string
    {
        $host = trim($host);
        $parsed = parse_url(str_contains($host, '://') ? $host : 'https://'.$host);

        return $parsed['host'] ?? $host;
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }
}
