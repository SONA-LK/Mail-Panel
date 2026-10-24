<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;

/**
 * Tamper evidence for the audit log. Every row carries HMAC(prev_hash | its own content), keyed with the
 * app key, so someone with database access alone cannot rewrite history and keep the chain valid.
 * A small checkpoint file with the newest row additionally exposes rows cut off the end of the log.
 */
class AuditChain
{
    public const REANCHOR = 'audit.chain_reanchored';

    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Fields that are part of the hash. The user and domain links are left out: removing a user clears them. */
    public static function canonical(array $row): string
    {
        $details = $row['details'] ?? null;
        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        return json_encode([
            $row['user_email'] ?? null,
            $row['action'],
            $row['target'] ?? null,
            self::sorted($details),
            $row['result'],
            $row['ip'] ?? null,
            (string) $row['created_at'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function hash(string $prev, array $row): string
    {
        return hash_hmac('sha256', $prev.'|'.self::canonical($row), (string) config('app.key'));
    }

    /** Databases may reorder JSON keys, so the order is fixed before hashing. */
    private static function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(fn ($x) => self::sorted($x), $v);
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }

    public static function writeCheckpoint(AuditLog $log): void
    {
        $path = config('panel.audit.head_path');
        if (! $path) {
            return;
        }

        try {
            file_put_contents($path, json_encode(['id' => $log->id, 'hash' => $log->hash]), LOCK_EX);
        } catch (\Throwable) {
            // A read-only folder must never stop the action being audited.
        }
    }

    /**
     * Checks the chain from the newest valid re-anchor (or from the start). Rows before that point were
     * written before chaining existed, or are no longer vouched for after an investigated break; they are
     * counted as "legacy". A re-anchor only counts when its own keyed hash is right, so a forged one cannot
     * be used to hide damage.
     *
     * @return array{ok:bool,checked:int,legacy:int,broken_at:?int,reason:?string,head:?string}
     */
    public function verify(): array
    {
        $start = 0;
        $anchors = AuditLog::where('action', self::REANCHOR)->where('prev_hash', self::GENESIS)->whereNotNull('hash')->orderByDesc('id')->get();
        foreach ($anchors as $anchor) {
            if (hash_equals(self::hash(self::GENESIS, $anchor->getAttributes()), $anchor->hash)) {
                $start = $anchor->id;
                break;
            }
        }

        $prev = null;
        $checked = 0;
        $legacy = $start ? AuditLog::where('id', '<', $start)->count() : 0;
        $head = null;
        $result = function (bool $ok, ?int $at = null, ?string $reason = null) use (&$checked, &$legacy, &$head) {
            return ['ok' => $ok, 'checked' => $checked, 'legacy' => $legacy, 'broken_at' => $at, 'reason' => $reason, 'head' => $head];
        };

        foreach (AuditLog::where('id', '>=', $start)->orderBy('id')->lazyById(1000) as $row) {
            if ($row->hash === null) {
                if ($prev !== null) {
                    return $result(false, $row->id, 'A row without a hash sits inside the chain.');
                }
                $legacy++;

                continue;
            }

            $expectedPrev = $prev ?? self::GENESIS;
            if ($row->prev_hash !== $expectedPrev) {
                return $result(false, $row->id, $prev === null ? 'The first chained row does not start the chain.' : 'A row before this one was removed or replaced.');
            }
            if (! hash_equals(self::hash($expectedPrev, $row->getAttributes()), $row->hash)) {
                return $result(false, $row->id, 'This row was changed after it was written.');
            }

            $prev = $head = $row->hash;
            $checked++;
        }

        if ($error = $this->checkpointError($head)) {
            return $result(false, null, $error);
        }

        return $result(true);
    }

    private function checkpointError(?string $head): ?string
    {
        $path = config('panel.audit.head_path');
        if (! $path || ! is_file($path)) {
            return null;
        }

        $saved = json_decode((string) @file_get_contents($path), true);
        if (! is_array($saved) || ! isset($saved['id'], $saved['hash'])) {
            return null;
        }

        $row = AuditLog::find($saved['id']);

        return $row && hash_equals((string) $row->hash, $saved['hash']) ? null : 'The newest rows are missing (the log was cut short).';
    }

    /** Whether the database refuses to change or delete audit rows. */
    public static function triggersInstalled(): bool
    {
        try {
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                return \Illuminate\Support\Facades\DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'audit_log_no_%')->count() === 2;
            }

            return count(\Illuminate\Support\Facades\DB::select("SHOW TRIGGERS WHERE `Table` = 'audit_log'")) >= 2;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Last result of `panel:audit-verify`, for the dashboard. */
    public static function remember(array $result): void
    {
        Cache::forever('audit.chain', $result + ['at' => now()->toIso8601String()]);
    }

    public static function lastResult(): ?array
    {
        return Cache::get('audit.chain');
    }
}
