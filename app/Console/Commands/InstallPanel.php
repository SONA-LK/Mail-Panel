<?php

namespace App\Console\Commands;

use App\Install\Installation;
use App\Install\Installer;
use Illuminate\Console\Command;
use Throwable;

class InstallPanel extends Command
{
    protected $signature = 'panel:install
        {--force : Run even if the panel looks installed}
        {--url= : Public address of the panel, e.g. https://mail-admin.example.com}
        {--app-name=Mail Panel}
        {--db=mysql : mysql or sqlite}
        {--db-host=localhost} {--db-port=3306} {--db-name=} {--db-user=} {--db-password=}
        {--cpanel-host=} {--cpanel-port=2083} {--cpanel-user=} {--cpanel-token=}
        {--no-verify-ssl : Do not verify the cPanel certificate}
        {--google-id=} {--google-secret=} {--google-domain=}
        {--admin-email=} {--admin-name=}
        {--no-import : Do not add the cPanel domains to the panel}';

    protected $description = 'Install the panel from the command line (same steps as the web wizard). Missing values are asked for.';

    public function handle(Installer $installer): int
    {
        if (Installation::isInstalled() && ! $this->option('force')) {
            $this->error('The panel is already installed. Use --force to run again.');

            return self::FAILURE;
        }

        $db = ['driver' => $this->option('db') === 'sqlite' ? 'sqlite' : 'mysql'];
        if ($db['driver'] === 'mysql') {
            $db += [
                'host' => $this->option('db-host'),
                'port' => (int) $this->option('db-port'),
                'database' => $this->need('db-name', 'Database name'),
                'username' => $this->need('db-user', 'Database user'),
                'password' => $this->option('db-password') ?? $this->secret('Database password (blank for none)') ?? '',
            ];
        }

        $cpanel = [
            'host' => $this->need('cpanel-host', 'cPanel host (e.g. server.yourhost.com)'),
            'port' => (int) $this->option('cpanel-port'),
            'user' => $this->need('cpanel-user', 'cPanel username'),
            'token' => $this->option('cpanel-token') ?: $this->secret('cPanel API token'),
            'verify_ssl' => ! $this->option('no-verify-ssl'),
        ];

        $url = $this->need('url', 'Panel address (https://...)');
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('The address must be a full URL such as https://mail-admin.example.com');

            return self::FAILURE;
        }
        $email = $this->need('admin-email', 'First admin Google email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        try {
            $this->components->info('Testing the database...');
            $installer->testDatabase($db);
            $this->components->info('Testing cPanel...');
            $found = $installer->testCpanel($cpanel);
            $this->line("  cPanel works: {$found['mailboxes']} mailbox(es), {$found['domains']} mail domain(s).");

            $this->components->info('Installing...');
            $result = $installer->install([
                'app_name' => $this->option('app-name'),
                'url' => $url,
                'db' => $db,
                'cpanel' => $cpanel,
                'google' => [
                    'id' => $this->need('google-id', 'Google OAuth client ID'),
                    'secret' => $this->option('google-secret') ?: $this->secret('Google OAuth client secret'),
                    'allowed_domain' => $this->option('google-domain') ?? '',
                ],
                'admin' => ['email' => $email, 'name' => $this->option('admin-name')],
                'import_domains' => ! $this->option('no-import'),
                'app_key' => Installation::temporaryKey() ?: null,
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['warnings'] as $w) {
            $this->warn($w);
        }
        $this->components->info("Installed. {$result['admin']} is the first super admin; {$result['imported']} domain(s) added.");
        $this->line('Add this cron job (cPanel, Cron Jobs, every minute):');
        $this->line('  '.$result['cron']);
        $this->line("Register this redirect URI in Google: ".rtrim($url, '/').'/auth/google/callback');

        return self::SUCCESS;
    }

    private function need(string $option, string $question): string
    {
        $value = $this->option($option);
        while ($value === null || $value === '') {
            if (! $this->input->isInteractive()) {
                throw new \RuntimeException("Missing --$option.");
            }
            $value = $this->ask($question);
        }

        return (string) $value;
    }
}
