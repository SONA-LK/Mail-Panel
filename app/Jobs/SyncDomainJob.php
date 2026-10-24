<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A manual sync from the settings screen runs here, not inside the web request. */
class SyncDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $domainId)
    {
    }

    public function handle(SyncService $sync): void
    {
        if ($domain = Domain::find($this->domainId)) {
            $sync->syncDomain($domain, 'manual');
        }
    }
}
