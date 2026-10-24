<?php

namespace App\Jobs;

use App\Models\BulkRow;
use App\Models\Domain;
use App\Services\AddressRules;
use App\Services\BulkImportService;
use App\Services\ForwarderService;
use App\Services\MailboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBulkRow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $rowId)
    {
    }

    /** Wait 1 then 3 minutes between attempts. */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(MailboxService $mailboxes, ForwarderService $forwarders, BulkImportService $bulk): void
    {
        $row = BulkRow::with('batch.user')->find($this->rowId);
        if (! $row || $row->status !== 'queued') {
            return;
        }

        $p = $row->payload;
        $user = $row->batch->user;
        $domain = Domain::forAddress($p['address']);

        // Re-check permission at execution time: the user may have been demoted since upload.
        if (! $domain || ! $user->canManage($domain)) {
            throw new \RuntimeException('Permission to manage this domain was removed before the row ran.');
        }

        $secret = null;
        if ($p['type'] === 'mailbox') {
            [$local] = AddressRules::split($p['address']);
            [, $password] = $mailboxes->create($user, $domain, $local, (int) $p['quota_mb'], $p['password'] !== '' ? $p['password'] : null);
            $secret = $p['password'] === '' ? $password : null;
        } else {
            $forwarders->add($user, $domain, $p['address'], $p['forward_to']);
        }

        $p['password'] = ''; // plan section 5: password fields are dropped once processed
        $row->update(['status' => 'done', 'payload' => $p, 'result_secret' => $secret, 'error_message' => null]);
        $row->batch->increment('done_rows');
        $bulk->refreshStatus($row->batch);
    }

    /** Called after the last retry. */
    public function failed(Throwable $e): void
    {
        $row = BulkRow::with('batch')->find($this->rowId);
        if (! $row || $row->status === 'done') {
            return;
        }

        $p = $row->payload ?? [];
        $p['password'] = '';
        $row->update(['status' => 'failed', 'payload' => $p, 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
        $row->batch->increment('failed_rows');
        app(BulkImportService::class)->refreshStatus($row->batch);
    }
}
