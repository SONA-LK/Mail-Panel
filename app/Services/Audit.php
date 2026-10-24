<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;

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
        return AuditLog::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email ?? $email,
            'action' => $action,
            'target' => $target,
            'domain_id' => $domain?->id,
            'details' => self::scrub($details) ?: null,
            'result' => $result,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
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
