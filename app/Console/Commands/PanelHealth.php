<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\HealthService;
use Illuminate\Console\Command;

class PanelHealth extends Command
{
    protected $signature = 'panel:health';

    protected $description = 'Check SPF, DKIM, DMARC and MX for every domain and cache the result for the dashboard';

    public function handle(HealthService $health): int
    {
        foreach (Domain::all() as $domain) {
            $r = $health->check($domain);
            $this->line("{$domain->name}: {$r['worst']}");
        }

        return self::SUCCESS;
    }
}
