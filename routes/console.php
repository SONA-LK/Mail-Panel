<?php

use App\Services\WorkerStatus;
use Illuminate\Support\Facades\Schedule;

// The only cron entry on the host is:  * * * * * cd /home/USER/mailpanel && php artisan schedule:run
// Shared hosting cannot keep a worker alive, so the queue is drained in short runs.

// First, so Settings can tell whether cron is running at all even if a later task fails.
Schedule::call(fn () => WorkerStatus::beat())->name('panel-heartbeat')->everyMinute();

Schedule::command('queue:work --stop-when-empty --max-jobs='.config('cpanel.bulk_per_run').' --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('panel:sync')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('panel:health')->dailyAt('03:30');
Schedule::command('panel:process-deletions')->dailyAt('04:00');
Schedule::command('panel:audit-verify')->dailyAt('05:00');
Schedule::command('queue:prune-failed --hours=720')->weekly();
