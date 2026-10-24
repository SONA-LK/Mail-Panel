<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\PendingDeletion;
use App\Models\User;
use App\Services\Audit;
use App\Services\CpanelException;
use App\Services\MailboxService;
use Illuminate\Console\Command;

class ProcessDeletions extends Command
{
    protected $signature = 'panel:process-deletions';

    protected $description = 'Delete suspended mailboxes whose wait period is over, only on domains that opted in to auto-delete';

    public function handle(MailboxService $mailboxes): int
    {
        $due = PendingDeletion::with('domain')->where('delete_after', '<=', now())->get()
            ->filter(fn ($p) => $p->domain->auto_delete_suspended);

        foreach ($due as $pending) {
            $mailbox = Mailbox::with('domain')->where('address', $pending->address)->first();
            if (! $mailbox) {
                $pending->delete();
                continue;
            }

            $actor = User::find($pending->requested_by)
                ?? User::where('role', User::ROLE_SUPER)->where('active', true)->first();
            if (! $actor) {
                continue;
            }

            try {
                $mailboxes->delete($actor, $mailbox);
                $this->line("Deleted {$pending->address}");
            } catch (CpanelException $e) {
                Audit::record($actor, 'mailbox.auto_delete', $pending->address, $pending->domain, ['error' => $e->getMessage()], 'failure');
                $this->error("{$pending->address}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
