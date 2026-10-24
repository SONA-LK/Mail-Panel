<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The only place that talks to cPanel. Every UAPI call goes through call(), so a
 * cPanel change to a function name or argument is fixed in one spot.
 */
class CpanelClient
{
    public function configured(): bool
    {
        return config('cpanel.host') && config('cpanel.user') && config('cpanel.token');
    }

    /**
     * Run a UAPI function and return its "data" payload.
     * Parameters go in a POST body so passwords never appear in URLs or server access logs.
     *
     * @throws CpanelException
     */
    public function call(string $module, string $function, array $params = []): mixed
    {
        if (! $this->configured()) {
            throw new CpanelException('cPanel is not configured. Set CPANEL_HOST, CPANEL_USER and CPANEL_TOKEN in .env.');
        }

        $url = sprintf('https://%s:%d/execute/%s/%s', $this->host(), config('cpanel.port'), $module, $function);

        try {
            $response = Http::withHeaders(['Authorization' => 'cpanel '.config('cpanel.user').':'.config('cpanel.token')])
                ->timeout(config('cpanel.timeout'))
                ->connectTimeout(10)
                ->withOptions(['verify' => (bool) config('cpanel.verify_ssl')])
                ->acceptJson()
                ->asForm()
                ->post($url, $params);
        } catch (ConnectionException $e) {
            // Never include the request (which holds the token) in the message.
            throw new CpanelException("Could not reach cPanel ($module::$function). Check host, port and network.");
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            throw new CpanelException("cPanel returned HTTP {$response->status()} for $module::$function.");
        }

        if (($json['status'] ?? 0) !== 1) {
            $errors = array_filter((array) ($json['errors'] ?? []));
            throw new CpanelException($errors ? implode('; ', $errors) : "cPanel rejected $module::$function.");
        }

        return $json['data'] ?? null;
    }

    /** Accepts a bare hostname or a pasted URL such as https://host:2083/. */
    public function host(): string
    {
        $host = (string) config('cpanel.host');
        $parsed = parse_url(str_contains($host, '://') ? $host : 'https://'.$host);

        return $parsed['host'] ?? $host;
    }

    public function webmailUrl(): string
    {
        return str_replace('{host}', $this->host(), (string) config('cpanel.webmail_url'));
    }
}
