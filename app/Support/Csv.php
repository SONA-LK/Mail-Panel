<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    /** Prefix cells that a spreadsheet would run as a formula. */
    public static function safe(?string $v): string
    {
        $v ??= '';

        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }

    /** @param iterable<array> $rows */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => is_string($c) ? self::safe($c) : $c, $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
