<?php

namespace App\Console\Commands;

use App\Services\CpanelClient;
use App\Services\CpanelException;
use App\Services\WorkerStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PanelDoctor extends Command
{
    protected $signature = 'panel:doctor';

    protected $description = 'Check what this PHP, database, cPanel connection and cron worker look like from the command line';

    public function handle(CpanelClient $cpanel, WorkerStatus $worker): int
    {
        $bad = 0;
        $row = function (string $label, bool $ok, string $detail = '') use (&$bad) {
            $this->line(sprintf(' [%s] %-34s %s', $ok ? ' ok ' : 'FAIL', $label, $detail));
            $bad += $ok ? 0 : 1;
        };

        $this->line('This is what the command line PHP sees. Run it with the same php as your cron entry.');
        $this->line(' PHP binary: '.PHP_BINARY.' ('.PHP_SAPI.')');
        $row('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);

        $missing = array_values(array_filter(['curl', 'mbstring', 'openssl', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'json', 'pdo'], fn ($e) => ! extension_loaded($e)));
        $driver = config('database.connections.'.config('database.default').'.driver');
        if ($driver && ! extension_loaded('pdo_'.$driver)) {
            $missing[] = 'pdo_'.$driver;
        }
        $row('Required PHP extensions', $missing === [], $missing ? 'missing: '.implode(', ', $missing) : 'all loaded');

        try {
            DB::connection()->getPdo();
            $row('Database', true, config('database.default').' / '.DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            $row('Database', false, strtok($e->getMessage(), "\n"));
        }

        if ($cpanel->configured()) {
            try {
                $count = count($cpanel->call('Email', 'list_pops') ?? []);
                $row('cPanel API', true, "{$cpanel->host()} answered, $count mailbox(es)");
            } catch (CpanelException $e) {
                $row('cPanel API', false, $e->getMessage());
            }
        } else {
            $row('cPanel API', false, 'not configured in .env');
        }

        $row('Queue driver is "database"', config('queue.default') === 'database', (string) config('queue.default'));

        $s = $worker->summary();
        $row('Cron heartbeat (last 3 minutes)', $s['running'], $s['last'] ? date('Y-m-d H:i:s', $s['last']) : 'never recorded');
        $this->line(" Queue: {$s['pending']} waiting, {$s['failed']} failed");

        if (! $s['running']) {
            $this->warn('The heartbeat is recorded by the cron job. If it stays stale, the cron entry is not running this PHP successfully.');
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
