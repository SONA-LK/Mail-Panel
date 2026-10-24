<?php

namespace App\Services;

class PasswordGenerator
{
    public static function generate(int $length = 18): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%^*-_=+'];
        $chars = [];

        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }

        $all = implode('', $sets);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher-Yates with a CSPRNG.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
