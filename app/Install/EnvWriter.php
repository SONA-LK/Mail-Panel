<?php

namespace App\Install;

use RuntimeException;

/** Writes values into .env, keeping every other line (comments, unknown keys) as it was. */
class EnvWriter
{
    /**
     * Update $path (or start it from $template when it does not exist yet).
     *
     * @param  array<string, string|int|bool|null>  $values
     */
    public static function write(string $path, string $template, array $values): void
    {
        $source = is_file($path) ? $path : $template;
        if (! is_file($source)) {
            throw new RuntimeException("Missing $source. Upload .env.example next to artisan.");
        }

        $lines = preg_split('/\R/', rtrim((string) file_get_contents($source), "\r\n"));

        foreach ($values as $key => $value) {
            $line = $key.'='.self::format($value);
            $found = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                }
            }
            if (! $found) {
                $lines[] = $line;
            }
        }

        if (@file_put_contents($path, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('Could not write '.$path.'. Make the folder writable, or create an empty .env file there and try again.');
        }
        @chmod($path, 0600);
    }

    public static function format(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $value = (string) $value;

        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@,+=-]+$/', $value)) {
            return $value;
        }
        // Single quotes keep $, # and spaces literal; only a value containing ' needs double quotes.
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
