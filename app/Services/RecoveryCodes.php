<?php

namespace App\Services;

use App\Models\RecoveryCode as RecoveryCodeModel;
use App\Models\User;

/** One-time codes for a lost phone or key. Only an HMAC of each code is stored; the plain codes are shown once. */
class RecoveryCodes
{
    public const COUNT = 8;

    // No 0/O/1/I/L, so a code is easy to read off paper.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return string[] the new codes in plain text; every older code stops working */
    public function generate(User $user): array
    {
        $user->recoveryCodes()->delete();

        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
            $user->recoveryCodes()->create(['code_hash' => $this->hash($raw)]);
        }

        return $codes;
    }

    /** @return int|null codes left after this one, or null when the code is not valid */
    public function consume(User $user, string $input): ?int
    {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (strlen($raw) !== 10) {
            return null;
        }

        $row = $user->recoveryCodes()->whereNull('used_at')->where('code_hash', $this->hash($raw))->first();
        if (! $row) {
            return null;
        }
        // One conditional update decides who used the code first; a parallel request with the same code gets 0 rows.
        if (RecoveryCodeModel::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return null;
        }

        return $this->remaining($user);
    }

    public function remaining(User $user): int
    {
        return $user->recoveryCodes()->whereNull('used_at')->count();
    }

    private function hash(string $raw): string
    {
        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }
}
