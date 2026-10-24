<?php

namespace App\Services;

use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\PendingDeletion;
use App\Models\User;
use Illuminate\Support\Collection;

class MailboxService
{
    public function __construct(private CpanelClient $cpanel)
    {
    }

    /**
     * Create a mailbox. Returns the model and the plain password (shown once, never stored).
     *
     * @return array{0: Mailbox, 1: string}
     */
    public function create(User $actor, Domain $domain, string $local, int $quotaMb, ?string $password = null): array
    {
        $address = "$local@{$domain->name}";
        $password = $password ?: PasswordGenerator::generate();

        $this->run($actor, 'mailbox.create', $address, $domain, ['quota_mb' => $quotaMb], function () use ($local, $domain, $password, $quotaMb) {
            $this->cpanel->call('Email', 'add_pop', [
                'email' => $local,
                'domain' => $domain->name,
                'password' => $password,
                'quota' => $quotaMb,
                'skip_update_db' => 1,
            ]);
        });

        $mailbox = Mailbox::updateOrCreate(['address' => $address], [
            'local_part' => $local,
            'domain_id' => $domain->id,
            'quota_mb' => $quotaMb ?: null,
            'used_mb' => 0,
            'suspended_login' => false,
            'suspended_incoming' => false,
            'last_synced_at' => now(),
        ]);

        return [$mailbox, $password];
    }

    public function resetPassword(User $actor, Mailbox $mailbox, ?string $password = null): string
    {
        $password = $password ?: PasswordGenerator::generate();

        $this->run($actor, 'mailbox.password_reset', $mailbox->address, $mailbox->domain, [], function () use ($mailbox, $password) {
            $this->cpanel->call('Email', 'passwd_pop', [
                'email' => $mailbox->local_part,
                'domain' => $mailbox->domain->name,
                'password' => $password,
            ]);
        });

        return $password;
    }

    public function changeQuota(User $actor, Mailbox $mailbox, int $quotaMb): void
    {
        $this->run($actor, 'mailbox.quota', $mailbox->address, $mailbox->domain, ['quota_mb' => $quotaMb], function () use ($mailbox, $quotaMb) {
            $this->cpanel->call('Email', 'edit_pop_quota', [
                'email' => $mailbox->local_part,
                'domain' => $mailbox->domain->name,
                'quota' => $quotaMb,
            ]);
        });

        $mailbox->update(['quota_mb' => $quotaMb ?: null]);
    }

    /** Block login and incoming mail, and schedule when deletion becomes allowed. */
    public function suspend(User $actor, Mailbox $mailbox): PendingDeletion
    {
        $this->run($actor, 'mailbox.suspend', $mailbox->address, $mailbox->domain, [], function () use ($mailbox) {
            $this->cpanel->call('Email', 'suspend_login', ['email' => $mailbox->address]);
            $this->cpanel->call('Email', 'suspend_incoming', ['email' => $mailbox->address]);
        });

        $mailbox->update(['suspended_login' => true, 'suspended_incoming' => true]);

        return PendingDeletion::updateOrCreate(['address' => $mailbox->address], [
            'domain_id' => $mailbox->domain_id,
            'suspended_at' => now(),
            'delete_after' => now()->addDays($mailbox->domain->suspend_days),
            'requested_by' => $actor->id,
        ]);
    }

    public function unsuspend(User $actor, Mailbox $mailbox): void
    {
        $this->run($actor, 'mailbox.unsuspend', $mailbox->address, $mailbox->domain, [], function () use ($mailbox) {
            $this->cpanel->call('Email', 'unsuspend_login', ['email' => $mailbox->address]);
            $this->cpanel->call('Email', 'unsuspend_incoming', ['email' => $mailbox->address]);
        });

        $mailbox->update(['suspended_login' => false, 'suspended_incoming' => false]);
        PendingDeletion::where('address', $mailbox->address)->delete();
    }

    /** Whether deletion is allowed right now under the domain's suspend-first rule. */
    public function deletionBlockedReason(Mailbox $mailbox): ?string
    {
        $domain = $mailbox->domain;
        if (! $domain->require_suspend_first) {
            return null;
        }

        $pending = PendingDeletion::where('address', $mailbox->address)->first();
        if (! $pending) {
            return "{$domain->name} requires a mailbox to be suspended for {$domain->suspend_days} days before it can be deleted.";
        }

        return $pending->isEligible()
            ? null
            : 'Deletion becomes allowed on '.$pending->delete_after->format('Y-m-d').'.';
    }

    /**
     * Delete a mailbox and its mail. Returns forwarders that still point at the address.
     *
     * @return Collection<int, Forwarder>
     */
    public function delete(User $actor, Mailbox $mailbox): Collection
    {
        if ($reason = $this->deletionBlockedReason($mailbox)) {
            throw new CpanelException($reason);
        }

        $dangling = Forwarder::where('destination', $mailbox->address)->get();

        $this->run($actor, 'mailbox.delete', $mailbox->address, $mailbox->domain, ['dangling_forwarders' => $dangling->pluck('source')->all()], function () use ($mailbox) {
            $this->cpanel->call('Email', 'delete_pop', [
                'email' => $mailbox->local_part,
                'domain' => $mailbox->domain->name,
            ]);
        });

        // Remove everything cached about the address (privacy: no leftovers).
        Forwarder::where('source', $mailbox->address)->delete();
        Autoresponder::where('address', $mailbox->address)->delete();
        PendingDeletion::where('address', $mailbox->address)->delete();
        $mailbox->delete();

        return $dangling;
    }

    /** Run a cPanel action and write one audit entry, success or failure. */
    private function run(User $actor, string $action, string $target, ?Domain $domain, array $details, callable $fn): void
    {
        try {
            $fn();
        } catch (CpanelException $e) {
            Audit::record($actor, $action, $target, $domain, $details + ['error' => $e->getMessage()], 'failure');
            throw $e;
        }

        Audit::record($actor, $action, $target, $domain, $details);
    }
}
