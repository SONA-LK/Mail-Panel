<?php

namespace App\Services;

use App\Jobs\SyncDomainJob;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Collection;

/** Brings the domains that already exist in cPanel into the panel so mailboxes can be created on them. */
class DomainImporter
{
    public function __construct(private CpanelClient $cpanel)
    {
    }

    /**
     * Domains cPanel can host mail for that the panel does not manage yet.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $known = Domain::pluck('name')->map(fn ($n) => strtolower($n))->all();

        return collect($this->cpanel->call('Email', 'list_mail_domains') ?? [])
            ->map(fn ($row) => strtolower(trim((string) ($row['domain'] ?? ''))))
            ->filter(fn ($name) => $name !== '' && ! in_array($name, $known, true))
            ->unique()->sort()->values()->all();
    }

    /**
     * Add the chosen domains (only ones cPanel really reports) and queue a sync for each.
     *
     * @param  list<string>  $names
     * @return Collection<int, Domain>
     */
    public function import(?User $actor, array $names, bool $queueSync = true): Collection
    {
        $allowed = $this->available();
        $created = collect();

        foreach (array_unique(array_map('strtolower', $names)) as $name) {
            if (! in_array($name, $allowed, true)) {
                continue;
            }

            $domain = Domain::create([
                'name' => $name,
                'purpose' => 'Imported from cPanel',
                'default_quota_mb' => 1024,
                'naming_pattern' => 'free',
                // Only a starting value: the first sync replaces it with what cPanel really does.
                'catchall_policy' => 'default',
                'forwarding_policy' => 'any',
                'suspend_days' => 30,
            ]);
            Audit::record($actor, 'domain.import', $name, $domain);
            $created->push($domain);

            if ($queueSync) {
                SyncDomainJob::dispatch($domain->id);
            }
        }

        return $created;
    }
}
