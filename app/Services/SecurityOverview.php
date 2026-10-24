<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The numbers behind the dashboard's security card (super admins only). */
class SecurityOverview
{
    /** @return array<int, array{label:string,value:string,level:string,hint:?string}> level is ok | warn | bad */
    public function items(): array
    {
        $since = now()->subDay();
        $count = fn (string $action) => AuditLog::where('action', $action)->where('created_at', '>=', $since)->count();

        $active = User::where('active', true)->withExists('passkeys')->get();
        $withFactor = $active->filter(fn ($u) => $u->hasTwoFactor())->count();
        $supersWithout = $active->filter(fn ($u) => $u->isSuperAdmin() && ! $u->hasTwoFactor())->count();
        $denied = $count('auth.failed');
        $locked = $count('auth.second_factor_locked');
        $chain = AuditChain::lastResult();
        $pending = ApprovalRequest::pending()->count();

        $items = [
            $this->item('Super admins without a second step', (string) $supersWithout, $supersWithout ? 'bad' : 'ok', $supersWithout ? 'They are forced to set one up at next sign-in.' : null),
            $this->item('People with a second step', "{$withFactor} of {$active->count()}", $withFactor === $active->count() ? 'ok' : 'warn', null),
            $this->item('Denied sign-ins, last 24 hours', (string) $denied, $denied >= config('panel.security.failed_login_burst') ? 'warn' : 'ok', null),
            $this->item('Second-step lock-outs, last 24 hours', (string) $locked, $locked ? 'warn' : 'ok', null),
            $this->item('Signed-in devices', (string) $this->activeSessions(), 'ok', null),
            $this->item('Changes waiting for approval', (string) $pending, $pending ? 'warn' : 'ok', $pending ? 'Open Approvals.' : null),
        ];

        $items[] = match (true) {
            $chain === null => $this->item('Audit log integrity', 'not checked yet', 'warn', 'The daily check has not run. Run: php artisan panel:audit-verify'),
            ! $chain['ok'] => $this->item('Audit log integrity', 'FAILED', 'bad', $chain['reason'] ?? null),
            default => $this->item('Audit log integrity', 'intact ('.Carbon::parse($chain['at'])->diffForHumans().')', 'ok', null),
        };
        $items[] = AuditChain::triggersInstalled()
            ? $this->item('Audit log locked in the database', 'yes', 'ok', null)
            : $this->item('Audit log locked in the database', 'no', 'warn', 'The database user lacks the TRIGGER privilege. The hash chain still detects tampering.');
        $items[] = $this->tokenAge();

        if (app()->isProduction() && config('app.debug')) {
            $items[] = $this->item('APP_DEBUG', 'on', 'bad', 'Turn it off: it shows secrets in error pages.');
        }
        if (app()->isProduction() && ! str_starts_with((string) config('app.url'), 'https://')) {
            $items[] = $this->item('APP_URL', 'not https', 'bad', 'Passkeys and secure cookies need https.');
        }

        return $items;
    }

    private function tokenAge(): array
    {
        $date = config('panel.security.token_created');
        if (! $date || ! ($created = rescue(fn () => Carbon::parse($date), null, false))) {
            return $this->item('cPanel API token age', 'unknown', 'warn', 'Set CPANEL_TOKEN_CREATED=YYYY-MM-DD in .env so the panel can remind you to rotate it.');
        }

        $days = (int) $created->diffInDays(now());

        return $this->item('cPanel API token age', "{$days} days", $days > 180 ? 'bad' : ($days > 90 ? 'warn' : 'ok'), $days > 90 ? 'Rotate it (see the admin guide).' : null);
    }

    private function activeSessions(): int
    {
        return config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->whereNotNull('user_id')->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)->count()
            : 0;
    }

    private function item(string $label, string $value, string $level, ?string $hint): array
    {
        return compact('label', 'value', 'level', 'hint');
    }
}
