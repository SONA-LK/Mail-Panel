<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\AuditChain;
use Illuminate\Console\Command;

class VerifyAuditLog extends Command
{
    protected $signature = 'panel:audit-verify {--reanchor= : After investigating a break, start a new chain and note why}';

    protected $description = 'Check that no audit log row was changed, removed or inserted. Runs daily; a failure alerts the super admins.';

    public function handle(AuditChain $chain): int
    {
        if ($note = $this->option('reanchor')) {
            Audit::reanchor($note);
            $this->warn('A new chain was started. The old part is no longer vouched for; the reason is kept in the log.');
        }

        $r = $chain->verify();
        AuditChain::remember($r);

        if ($r['ok']) {
            $this->info("Audit log intact: {$r['checked']} chained row(s), {$r['legacy']} older row(s) from before chaining.");

            return self::SUCCESS;
        }

        $this->error('Audit log FAILED its check'.($r['broken_at'] ? " at row #{$r['broken_at']}" : '').': '.$r['reason']);
        $this->line('Keep a copy of the database before changing anything. When you are sure what happened, run again with --reanchor="reason".');
        Audit::record(null, 'audit.chain_broken', $r['broken_at'] ? "row #{$r['broken_at']}" : 'end of log', null, ['reason' => $r['reason']], 'failure');

        return self::FAILURE;
    }
}
