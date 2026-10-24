<?php

namespace App\Console\Commands;

use App\Services\CpanelException;
use App\Services\DomainImporter;
use Illuminate\Console\Command;

class ImportDomains extends Command
{
    protected $signature = 'panel:import-domains {--dry-run : Only list what would be imported}';

    protected $description = 'Add the mail domains that exist in cPanel but not yet in the panel';

    public function handle(DomainImporter $importer): int
    {
        try {
            $available = $importer->available();
        } catch (CpanelException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $available) {
            $this->info('Nothing to import: every mail domain in cPanel is already in the panel.');

            return self::SUCCESS;
        }

        foreach ($available as $name) {
            $this->line($name);
        }

        if ($this->option('dry-run')) {
            $this->info(count($available).' domain(s) would be imported.');

            return self::SUCCESS;
        }

        $this->info($importer->import(null, $available)->count().' domain(s) imported; sync queued for each.');

        return self::SUCCESS;
    }
}
