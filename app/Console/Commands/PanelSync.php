<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\SyncService;
use Illuminate\Console\Command;

class PanelSync extends Command
{
    protected $signature = 'panel:sync {domain? : Only this domain name}';

    protected $description = 'Sync mailboxes, forwarders, autoresponders and filters from cPanel into the cache';

    public function handle(SyncService $sync): int
    {
        $domains = Domain::when($this->argument('domain'), fn ($q, $d) => $q->where('name', $d))->get();
        $failed = false;

        foreach ($sync->syncAll($domains, 'schedule') as $run) {
            $name = $run->domain->name;
            $this->line("$name: +{$run->added} ~{$run->changed} -{$run->removed}".($run->errors ? " ERRORS: {$run->errors}" : ''));
            $failed = $failed || $run->errors;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
