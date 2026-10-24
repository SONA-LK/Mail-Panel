<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Domain;
use App\Models\User;
use RuntimeException;

/**
 * Four eyes for the changes that are hard to take back: removing a domain from the panel or removing a super
 * admin. One super admin asks, a different one approves. With only one active super admin there is nobody to
 * ask, so the change goes straight through (the audit log and the alerts still record it).
 */
class Approvals
{
    public function required(): bool
    {
        return config('panel.security.four_eyes')
            && User::where('role', User::ROLE_SUPER)->where('active', true)->count() >= 2;
    }

    /** Ask for approval. Asking twice for the same thing returns the open request. */
    public function request(User $by, string $action, string $target, array $payload): ApprovalRequest
    {
        $open = ApprovalRequest::pending()->where('action', $action)->where('target', $target)->first();
        if ($open) {
            return $open;
        }

        $request = ApprovalRequest::create([
            'action' => $action, 'target' => $target, 'payload' => $payload,
            'requested_by' => $by->id, 'requested_by_email' => $by->email,
            'expires_at' => now()->addHours(config('panel.security.four_eyes_hours')),
        ]);
        Audit::record($by, 'approval.requested', $target, null, ['action' => $action, 'request' => $request->id]);

        return $request;
    }

    public function approve(User $by, ApprovalRequest $request): void
    {
        $this->assertOpen($request);
        if ($request->requested_by === $by->id) {
            throw new RuntimeException('A different super admin has to approve this. You asked for it.');
        }

        // The change is made first; it only counts as approved if it worked.
        $this->execute($by, $request);

        $request->forceFill(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now()])->save();
        Audit::record($by, 'approval.approved', $request->target, null, ['action' => $request->action, 'asked_by' => $request->requested_by_email]);
    }

    public function reject(User $by, ApprovalRequest $request): void
    {
        $this->assertOpen($request);

        $request->forceFill(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now()])->save();
        Audit::record($by, 'approval.rejected', $request->target, null, ['action' => $request->action, 'asked_by' => $request->requested_by_email]);
    }

    private function assertOpen(ApprovalRequest $request): void
    {
        if ($request->status !== 'pending' || $request->expires_at->isPast()) {
            throw new RuntimeException('This request is no longer open.');
        }
    }

    private function execute(User $by, ApprovalRequest $request): void
    {
        $details = ['approved_by' => $by->email, 'asked_by' => $request->requested_by_email];

        if ($request->action === ApprovalRequest::DOMAIN_REMOVE) {
            $domain = Domain::find($request->payload['domain_id'] ?? 0);
            if (! $domain) {
                throw new RuntimeException('That domain no longer exists.');
            }
            Audit::record($request->requester ?? $by, 'domain.remove', $domain->name, $domain, $details);
            $domain->delete();

            return;
        }

        if ($request->action === ApprovalRequest::USER_DELETE) {
            $user = User::find($request->payload['user_id'] ?? 0);
            if (! $user) {
                throw new RuntimeException('That person no longer exists.');
            }
            // Re-checked now: things may have changed since the request was made.
            if ($user->isSuperAdmin() && ! User::where('role', User::ROLE_SUPER)->where('active', true)->where('id', '!=', $user->id)->exists()) {
                throw new RuntimeException('That is the last active super admin.');
            }
            Audit::record($request->requester ?? $by, 'user.delete', $user->email, null, $details);
            $user->revokeSessions();
            $user->delete();

            return;
        }

        if ($request->action === ApprovalRequest::USER_DEMOTE) {
            $user = User::find($request->payload['user_id'] ?? 0);
            if (! $user) {
                throw new RuntimeException('That person no longer exists.');
            }
            if ($user->isSuperAdmin() && ! User::where('role', User::ROLE_SUPER)->where('active', true)->where('id', '!=', $user->id)->exists()) {
                throw new RuntimeException('That is the last active super admin.');
            }
            $user->update(['role' => $request->payload['role'], 'active' => $request->payload['active']]);
            $user->revokeSessions();
            Audit::record($request->requester ?? $by, 'user.update', $user->email, null, ['role' => $user->role, 'active' => $user->active] + $details);

            return;
        }

        throw new RuntimeException('Unknown request.');
    }
}
