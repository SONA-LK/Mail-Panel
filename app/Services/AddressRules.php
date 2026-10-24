<?php

namespace App\Services;

use App\Models\Domain;

class AddressRules
{
    public const LOCAL_REGEX = '/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/';

    public static function isEmail(string $value): bool
    {
        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    /** Returns an error message, or null when the local part is acceptable for this domain. */
    public static function localPartError(string $local, Domain $domain): ?string
    {
        if (! preg_match(self::LOCAL_REGEX, $local)) {
            return 'Use 1-64 letters, digits, dots, dashes or underscores, starting and ending with a letter or digit.';
        }

        return match ($domain->naming_pattern) {
            'first.last' => preg_match('/^[a-z]+\.[a-z]+$/', $local) ? null : "This domain requires the pattern first.last (letters only).",
            'department' => preg_match('/^[a-z0-9-]+$/', $local) ? null : 'This domain requires a department name (letters, digits and dashes).',
            default => null,
        };
    }

    /** Split "a@b.com" into [local, domain]; null when malformed. */
    public static function split(string $address): ?array
    {
        $at = strrpos($address, '@');

        return $at === false ? null : [substr($address, 0, $at), substr($address, $at + 1)];
    }
}
