<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(
        ?User $user,
        string $action,
        ?string $target = null,
        ?Domain $domain = null,
        array $details = [],
        string $result = 'success',
        ?string $email = null,
    ): AuditLog {
        $row = [
            'user_id' => $user?->id,
            'user_email' => $user?->email ?? $email,
            'action' => $action,
            'target' => $target,
            'domain_id' => $domain?->id,
            'details' => self::scrub($details) ?: null,
            'result' => $result,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        $log = self::chained($row);

        // Only once the row is really committed, so a rolled-back caller cannot leave a checkpoint for a row that is gone.
        DB::afterCommit(fn () => AuditChain::writeCheckpoint($log));
        SecurityAlerts::consider($log);

        return $log;
    }

    /**
     * Chain the row to the previous one. Two requests must not take the same predecessor, so the read and the
     * insert happen under one lock shared by all processes (the cache lock works on the database and file
     * drivers alike). If the lock cannot be had in time the row is still written: losing an audit entry is
     * worse than a fork the daily check will report.
     */
    private static function chained(array $row): AuditLog
    {
        $write = function () use ($row) {
            return DB::transaction(function () use ($row) {
                $prev = AuditLog::whereNotNull('hash')->orderByDesc('id')->lockForUpdate()->value('hash') ?? AuditChain::GENESIS;
                $row['prev_hash'] = $prev;
                $row['hash'] = AuditChain::hash($prev, $row);

                return AuditLog::create($row);
            });
        };

        try {
            return Cache::lock('audit-chain', 10)->block(5, $write);
        } catch (LockTimeoutException) {
            return $write();
        }
    }

    /** Start a new chain after a break has been investigated. Visible in the log and in the alerts. */
    public static function reanchor(?string $note = null): AuditLog
    {
        $row = [
            'user_id' => null, 'user_email' => null, 'action' => AuditChain::REANCHOR, 'target' => null, 'domain_id' => null,
            'details' => $note ? ['note' => $note] : null, 'result' => 'success', 'ip' => null, 'created_at' => now()->format('Y-m-d H:i:s'),
            'prev_hash' => AuditChain::GENESIS,
        ];
        $row['hash'] = AuditChain::hash(AuditChain::GENESIS, $row);
        $log = AuditLog::create($row);
        AuditChain::writeCheckpoint($log);

        return $log;
    }

    /** Passwords and secrets must never reach the log. */
    private static function scrub(array $details): array
    {
        foreach ($details as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token/i', $key)) {
                unset($details[$key]);
            } elseif (is_array($value)) {
                $details[$key] = self::scrub($value);
            }
        }

        return $details;
    }
}
