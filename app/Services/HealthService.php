<?php

namespace App\Services;

use App\Models\Domain;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * SPF, DKIM, DMARC and MX status from public DNS, so problems show up before mail bounces.
 */
class HealthService
{
    public const TTL_MINUTES = 360;

    /** @param Closure(string, int): array|null $resolver  returns dns_get_record-style rows; injectable for tests */
    public function __construct(private ?Closure $resolver = null)
    {
        $this->resolver ??= static fn (string $host, int $type) => @dns_get_record($host, $type) ?: [];
    }

    /** @return array{checked_at: string, checks: list<array{name: string, status: string, detail: string, hint: string}>, worst: string} */
    public function check(Domain $domain): array
    {
        $name = strtolower($domain->name);
        $checks = [$this->mx($name), $this->spf($name), $this->dkim($name), $this->dmarc($name)];

        $order = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        $worst = collect($checks)->sortByDesc(fn ($c) => $order[$c['status']])->first()['status'];

        $result = ['checked_at' => now()->toDateTimeString(), 'checks' => $checks, 'worst' => $worst];
        Cache::put($this->key($domain), $result, now()->addMinutes(self::TTL_MINUTES * 2));

        return $result;
    }

    public function cached(Domain $domain): ?array
    {
        return Cache::get($this->key($domain));
    }

    private function key(Domain $domain): string
    {
        return 'health:'.$domain->id;
    }

    private function txt(string $host): array
    {
        return array_map(
            fn ($r) => (string) ($r['txt'] ?? implode('', $r['entries'] ?? [])),
            ($this->resolver)($host, DNS_TXT)
        );
    }

    private function row(string $name, string $status, string $detail, string $hint = ''): array
    {
        return compact('name', 'status', 'detail', 'hint');
    }

    private function mx(string $domain): array
    {
        $rows = ($this->resolver)($domain, DNS_MX);
        if (! $rows) {
            return $this->row('MX', 'fail', 'No MX records found.', 'Add MX records in cPanel, Zone Editor, or point the domain nameservers at your host.');
        }

        usort($rows, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return $this->row('MX', 'ok', implode(', ', array_map(fn ($r) => ($r['pri'] ?? '?').' '.($r['target'] ?? '?'), $rows)));
    }

    private function spf(string $domain): array
    {
        $records = array_values(array_filter($this->txt($domain), fn ($t) => stripos($t, 'v=spf1') === 0));

        if (! $records) {
            return $this->row('SPF', 'fail', 'No SPF record.', 'In cPanel, Email Deliverability, repair SPF for this domain.');
        }
        if (count($records) > 1) {
            return $this->row('SPF', 'fail', 'More than one SPF record.', 'Merge them into a single v=spf1 record; receivers reject duplicates.');
        }
        if (! preg_match('/[-~?+]all\b/i', $records[0])) {
            return $this->row('SPF', 'warn', $records[0], 'End the record with ~all or -all.');
        }

        return $this->row('SPF', 'ok', $records[0]);
    }

    private function dkim(string $domain): array
    {
        foreach (['default', 'x', 'google', 'selector1'] as $selector) {
            foreach ($this->txt("$selector._domainkey.$domain") as $t) {
                if (stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false) {
                    return $this->row('DKIM', 'ok', "Selector '$selector' is published.");
                }
            }
        }

        return $this->row('DKIM', 'fail', 'No DKIM key found for the common selectors.', 'In cPanel, Email Deliverability, install the suggested DKIM record.');
    }

    private function dmarc(string $domain): array
    {
        $records = array_values(array_filter($this->txt("_dmarc.$domain"), fn ($t) => stripos($t, 'v=DMARC1') === 0));

        if (! $records) {
            return $this->row('DMARC', 'fail', 'No DMARC record.', 'Add a TXT record _dmarc with v=DMARC1; p=none; rua=mailto:you@yourdomain to start.');
        }
        if (preg_match('/\bp=none\b/i', $records[0])) {
            return $this->row('DMARC', 'warn', $records[0], 'Policy is p=none (monitoring only). Move to quarantine once reports look clean.');
        }

        return $this->row('DMARC', 'ok', $records[0]);
    }
}
