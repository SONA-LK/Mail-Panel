<?php

namespace App\Support;

/** Small colour maths for themes: WCAG contrast and safe adjustments. Only ever handles #rrggbb. */
class Color
{
    public static function valid(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9a-f]{6}$/i', $hex);
    }

    /** @return array{int,int,int} */
    public static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(fn ($c) => max(0, min(255, (int) round($c))), $rgb));
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Blend $a toward $b; $t = 0 gives $a, 1 gives $b. */
    public static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);

        return self::hex(array_map(fn ($i) => $x[$i] + ($y[$i] - $x[$i]) * $t, [0, 1, 2]));
    }

    /** Text colour that reads best on $bg. */
    public static function ink(string $bg): string
    {
        return self::contrast($bg, '#ffffff') >= self::contrast($bg, '#111827') ? '#ffffff' : '#111827';
    }

    /** Nudge $fg toward black or white until it reads on $bg at WCAG AA (4.5:1). Keeps the hue as long as it can. */
    public static function readable(string $fg, string $bg, float $min = 4.5): string
    {
        $target = self::luminance($bg) > 0.4 ? '#000000' : '#ffffff';
        for ($t = 0.0; $t <= 1.0; $t += 0.04) {
            $c = self::mix($fg, $target, $t);
            if (self::contrast($c, $bg) >= $min) {
                return $c;
            }
        }

        return $target;
    }
}
