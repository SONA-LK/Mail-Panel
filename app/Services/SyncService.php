<?php

namespace App\Services;

use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reads mailboxes, forwarders, autoresponders and filters from cPanel and makes the
 * cache match. If cPanel cannot be read for a domain, its cache is left untouched.
 */
class SyncService
{
    public function __construct(private CpanelClient $cpanel)
    {
    }

    /** Sync the given domains (all when null) and record one sync_runs row per domain. */
    public function syncAll(?iterable $domains = null, string $trigger = 'schedule'): array
    {
        $runs = [];
        foreach ($domains ?? Domain::all() as $domain) {
            $runs[] = $this->syncDomain($domain, $trigger);
        }

        return $runs;
    }

    public function syncDomain(Domain $domain, string $trigger = 'schedule'): SyncRun
    {
        $run = SyncRun::create(['domain_id' => $domain->id, 'trigger' => $trigger, 'started_at' => now()]);
        $stats = ['added' => 0, 'changed' => 0, 'removed' => 0];
        $errors = [];
        $syncStart = now();

        // Each step is independent: one failing endpoint must not hide the others' results.
        foreach (['mailboxes', 'forwarders', 'autoresponders', 'filters'] as $name) {
            try {
                match ($name) {
                    'mailboxes' => $this->syncMailboxes($domain, $syncStart, $stats),
                    'forwarders' => $this->syncForwarders($domain, $syncStart, $stats),
                    'autoresponders' => $this->syncAutoresponders($domain, $syncStart, $stats),
                    'filters' => $this->syncFilters($domain, $syncStart, $stats),
                };
            } catch (Throwable $e) {
                $errors[] = "$name: ".$e->getMessage();
            }
        }

        $run->update($stats + [
            'finished_at' => now(),
            'errors' => $errors ? implode("\n", $errors) : null,
        ]);

        return $run;
    }

    private function syncMailboxes(Domain $domain, $syncStart, array &$stats): void
    {
        $rows = $this->cpanel->call('Email', 'list_pops_with_disk', ['domain' => $domain->name]) ?? [];
        $seen = [];

        foreach ($rows as $row) {
            $address = strtolower((string) ($row['email'] ?? (($row['user'] ?? '').'@'.($row['domain'] ?? ''))));
            if (! str_contains($address, '@') || ! str_ends_with($address, '@'.strtolower($domain->name))) {
                continue; // skips the cPanel system account
            }

            $quota = $row['diskquota'] ?? ($row['_diskquota'] ?? null);
            $attrs = [
                'local_part' => strstr($address, '@', true),
                'domain_id' => $domain->id,
                'quota_mb' => is_numeric($quota) && (float) $quota > 0 ? (int) round((float) $quota) : null,
                'used_mb' => is_numeric($row['diskused'] ?? null) ? round((float) $row['diskused'], 2) : 0,
                'suspended_login' => (bool) ($row['suspended_login'] ?? false),
                'suspended_incoming' => (bool) ($row['suspended_incoming'] ?? false),
                'last_synced_at' => $syncStart,
            ];

            $this->upsert(Mailbox::firstOrNew(['address' => $address]), $attrs, $stats, ['last_synced_at', 'used_mb']);
            $seen[] = $address;
        }

        $this->prune(Mailbox::where('domain_id', $domain->id), 'address', $seen, $stats);
    }

    private function syncForwarders(Domain $domain, $syncStart, array &$stats): void
    {
        $seen = [];

        $address = $this->cpanel->call('Email', 'list_forwarders', ['domain' => $domain->name]) ?? [];
        foreach ($address as $row) {
            $src = strtolower((string) ($row['dest'] ?? ''));
            $dst = strtolower((string) ($row['forward'] ?? ''));
            if ($src && $dst) {
                $seen[] = $this->saveForwarder($domain, $src, $dst, Forwarder::TYPE_ADDRESS, $syncStart, $stats);
            }
        }

        $domainFwd = $this->cpanel->call('Email', 'list_domain_forwarders', ['domain' => $domain->name]) ?? [];
        foreach ($domainFwd as $row) {
            $dst = strtolower((string) ($row['forward'] ?? $row['destdomain'] ?? ''));
            if ($dst) {
                $seen[] = $this->saveForwarder($domain, "*@{$domain->name}", '*@'.ltrim($dst, '*@'), Forwarder::TYPE_DOMAIN, $syncStart, $stats);
            }
        }

        $catchAll = $this->cpanel->call('Email', 'list_default_address', ['domain' => $domain->name]) ?? [];
        if (! empty($catchAll[0]['defaultaddress'])) {
            // cPanel wraps fail/blackhole values in literal quotes: ":fail: No Such User Here".
            $value = trim((string) $catchAll[0]['defaultaddress'], " \t\"'");
            $dest = str_starts_with($value, ':fail:') ? ':fail:' : (str_starts_with($value, ':blackhole:') ? ':blackhole:' : strtolower($value));
            $seen[] = $this->saveForwarder($domain, "*@{$domain->name}", $dest, Forwarder::TYPE_CATCHALL, $syncStart, $stats);

            // Keep the domain's recorded policy equal to what cPanel really does (it can be changed in cPanel).
            [$policy, $target] = match (true) {
                $dest === ':blackhole:' => ['discard', null],
                $dest === ':fail:' => ['reject', null],
                $dest === strtolower((string) config('cpanel.user')) => ['default', null],
                default => ['forward', $dest],
            };
            $domain->update(['catchall_policy' => $policy, 'catchall_target' => $target]);
        }

        $this->prune(Forwarder::where('domain_id', $domain->id), 'id', $seen, $stats);
    }

    private function saveForwarder(Domain $domain, string $src, string $dst, string $type, $syncStart, array &$stats): int
    {
        $model = Forwarder::firstOrNew(['source' => $src, 'destination' => $dst, 'type' => $type]);
        $this->upsert($model, ['domain_id' => $domain->id, 'last_synced_at' => $syncStart], $stats, ['last_synced_at']);

        return $model->id;
    }

    private function syncAutoresponders(Domain $domain, $syncStart, array &$stats): void
    {
        $rows = $this->cpanel->call('Email', 'list_auto_responders', ['domain' => $domain->name]) ?? [];
        $seen = [];

        foreach ($rows as $row) {
            $address = strtolower((string) ($row['email'] ?? ''));
            if (! $address) {
                continue;
            }
            $this->upsert(Autoresponder::firstOrNew(['address' => $address]), [
                'domain_id' => $domain->id, 'subject' => $row['subject'] ?? null, 'last_synced_at' => $syncStart,
            ], $stats, ['last_synced_at']);
            $seen[] = $address;
        }

        $this->prune(Autoresponder::where('domain_id', $domain->id), 'address', $seen, $stats);
    }

    private function syncFilters(Domain $domain, $syncStart, array &$stats): void
    {
        $seen = [];

        foreach (Mailbox::where('domain_id', $domain->id)->pluck('address') as $address) {
            foreach ($this->cpanel->call('Email', 'list_filters', ['account' => $address]) ?? [] as $f) {
                $name = (string) ($f['filtername'] ?? '');
                if ($name === '') {
                    continue;
                }
                $model = MailFilter::firstOrNew(['scope' => 'mailbox', 'address' => $address, 'name' => $name]);
                $this->upsert($model, [
                    'domain_id' => $domain->id, 'rule_summary' => $this->summarise($f), 'last_synced_at' => $syncStart,
                ], $stats, ['last_synced_at']);
                $seen[] = $model->id;
            }
        }

        $this->prune(MailFilter::where('domain_id', $domain->id)->where('scope', 'mailbox'), 'id', $seen, $stats);
    }

    private function summarise(array $filter): string
    {
        $rules = collect($filter['rules'] ?? [])->map(
            fn ($r) => trim(($r['part'] ?? '').' '.($r['match'] ?? '').' '.($r['val'] ?? ''))
        )->implode(' and ');
        $actions = collect($filter['actions'] ?? [])->map(
            fn ($a) => trim(($a['action'] ?? '').' '.($a['dest'] ?? ''))
        )->implode(', ');

        return mb_substr(trim("if $rules then $actions"), 0, 500);
    }

    /** Create or update, counting only meaningful changes (not the sync timestamp or usage). */
    private function upsert(Model $model, array $attrs, array &$stats, array $ignoreForChange): void
    {
        $model->fill($attrs);

        if (! $model->exists) {
            $stats['added']++;
        } elseif (! empty(array_diff_key($model->getDirty(), array_flip($ignoreForChange)))) {
            $stats['changed']++;
        }

        $model->save();
    }

    /** Remove cached rows that cPanel no longer reports. */
    private function prune($query, string $key, array $seen, array &$stats): void
    {
        $stale = $query->when($seen, fn ($q) => $q->whereNotIn($key, $seen));
        $stats['removed'] += $stale->count();
        $stale->delete();
    }
}
