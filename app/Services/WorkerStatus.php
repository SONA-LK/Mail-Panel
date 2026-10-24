<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is the cron job really running? "Sync all domains" and bulk imports only queue work; a worker started by
 * cron does it. The schedule records a heartbeat every minute so the panel can say when that is not happening.
 */
class WorkerStatus
{
    /** The cron job should fire every minute; allow a few missed ones before calling it stalled. */
    public const FRESH_SECONDS = 180;

    public static function beat(): void
    {
        Cache::forever('scheduler.heartbeat', time());
    }

    public function lastBeat(): ?int
    {
        try {
            $v = Cache::get('scheduler.heartbeat');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($v) ? (int) $v : null;
    }

    public function running(): bool
    {
        $beat = $this->lastBeat();

        return $beat !== null && time() - $beat <= self::FRESH_SECONDS;
    }

    public function pendingJobs(): int
    {
        return $this->count('jobs');
    }

    /** Seconds the oldest waiting job has been waiting, or null when the queue is empty. */
    public function oldestPendingSeconds(): ?int
    {
        try {
            $oldest = DB::table('jobs')->min('created_at');
        } catch (Throwable) {
            return null;
        }

        return $oldest === null ? null : max(0, time() - (int) $oldest);
    }

    public function failedJobs(): int
    {
        return $this->count('failed_jobs');
    }

    /** @return array{running: bool, last: ?int, pending: int, oldest: ?int, failed: int} */
    public function summary(): array
    {
        return [
            'running' => $this->running(),
            'last' => $this->lastBeat(),
            'pending' => $this->pendingJobs(),
            'oldest' => $this->oldestPendingSeconds(),
            'failed' => $this->failedJobs(),
        ];
    }

    private function count(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
