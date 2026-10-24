<?php

namespace App\Services;

use App\Mail\SecurityAlert;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails super admins (and the person concerned) about events that matter for security. Mail is queued so a
 * slow mail server never slows a request, and nothing here can make the audited action fail.
 */
class SecurityAlerts
{
    /** Events that always alert the super admins. */
    private const EVENTS = [
        'auth.second_factor_locked' => 'Too many wrong second-step answers; the account is locked for a while',
        'auth.recovery_code_used' => 'A recovery code was used to sign in',
        'security.totp_enabled' => 'An authenticator app was added',
        'security.totp_removed' => 'An authenticator app was removed',
        'security.passkey_added' => 'A passkey was added',
        'security.passkey_removed' => 'A passkey was removed',
        'security.recovery_codes_made' => 'New recovery codes were made',
        'user.two_factor_reset' => 'A second sign-in step was reset for a person',
        'user.delete' => 'A panel user was removed',
        'user.demote' => 'A super admin was demoted or disabled',
        'user.make_admin' => 'A super admin was created from the command line',
        'brand.update' => 'Company branding was changed',
        'brand.restore' => 'An older company branding was restored',
        'domain.remove' => 'A domain was removed from the panel',
        'bulk.confirm' => 'A bulk import was started',
        'approval.requested' => 'A change is waiting for a second super admin to approve',
        'approval.approved' => 'A requested change was approved and carried out',
        'approval.rejected' => 'A requested change was rejected',
        'audit.chain_broken' => 'The audit log failed its integrity check',
    ];

    /** Events a person should also hear about themselves, in case it was not them. */
    private const OWN = ['security.totp_enabled', 'security.totp_removed', 'security.passkey_added', 'security.passkey_removed', 'security.recovery_codes_made', 'auth.recovery_code_used', 'auth.second_factor_locked'];

    public static function consider(AuditLog $log): void
    {
        if (! config('panel.security.alerts')) {
            return;
        }

        try {
            (new self)->evaluate($log);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function evaluate(AuditLog $log): void
    {
        $action = $log->action;

        if (isset(self::EVENTS[$action]) && $log->result === 'success' || $action === 'auth.second_factor_locked' || $action === 'audit.chain_broken') {
            $this->send($log, self::EVENTS[$action], $this->admins(), in_array($action, self::OWN, true));

            return;
        }

        if (in_array($action, ['user.create', 'user.update'], true) && ($log->details['role'] ?? null) === User::ROLE_SUPER) {
            $this->send($log, $action === 'user.create' ? 'A super admin was added' : 'A super admin was changed', $this->admins(), false);

            return;
        }

        if ($action === 'auth.failed') {
            $this->failedBurst($log);

            return;
        }

        if ($action === 'auth.login') {
            $this->newAddress($log);
        }
    }

    private function failedBurst(AuditLog $log): void
    {
        $recent = AuditLog::where('action', 'auth.failed')->where('created_at', '>=', now()->subMinutes(10))->count();

        if ($recent >= config('panel.security.failed_login_burst') && Cache::add('alert:failed-burst', 1, now()->addMinutes(30))) {
            $this->send($log, "{$recent} denied sign-ins in the last 10 minutes", $this->admins(), false);
        }
    }

    /** A sign-in from an address this person has not used lately goes to them, and to the admins if they are one. */
    private function newAddress(AuditLog $log): void
    {
        if (! $log->ip || ! $log->user_id) {
            return;
        }

        $before = AuditLog::where('user_id', $log->user_id)->where('action', 'auth.login')->where('id', '<', $log->id)
            ->where('created_at', '>=', now()->subDays(config('panel.security.new_ip_days')));

        // The very first sign-in has nothing to compare with, and a known address is not news.
        if (! (clone $before)->exists() || (clone $before)->where('ip', $log->ip)->exists()) {
            return;
        }

        $user = User::find($log->user_id);
        if ($user) {
            $recipients = collect([$user->email]);
            if ($user->isSuperAdmin()) {
                $recipients = $recipients->merge($this->admins());
            }
            $this->send($log, 'Sign-in from a new address', $recipients->unique()->all(), false);
        }
    }

    private function admins(): array
    {
        return User::where('role', User::ROLE_SUPER)->where('active', true)->pluck('email')->all();
    }

    private function send(AuditLog $log, string $headline, array $recipients, bool $includeActor): void
    {
        if ($includeActor && $log->user_email) {
            $recipients[] = $log->user_email;
        }

        foreach (array_unique($recipients) as $to) {
            // The same event for the same person is only mailed once in a couple of minutes.
            if (! Cache::add('alert:'.sha1($to.'|'.$log->action.'|'.$log->user_email.'|'.$log->target), 1, now()->addMinutes(2))) {
                continue;
            }
            Mail::to($to)->queue(new SecurityAlert($headline, [
                'Event' => $log->action,
                'Person' => $log->user_email ?: 'not signed in',
                'Target' => $log->target ?: '-',
                'Address' => $log->ip ?: '-',
                'Time' => $log->created_at->format('Y-m-d H:i:s T'),
                'Details' => $log->details ? json_encode($log->details, JSON_UNESCAPED_SLASHES) : '-',
            ], route('audit.index')));
        }
    }
}
