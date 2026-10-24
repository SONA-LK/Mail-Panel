<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ROLE_SUPER = 'super_admin';
    public const ROLE_MANAGER = 'domain_manager';
    public const ROLE_READONLY = 'read_only';
    public const ROLES = [self::ROLE_SUPER, self::ROLE_MANAGER, self::ROLE_READONLY];

    protected $fillable = ['name', 'email', 'google_id', 'role', 'active', 'last_login_at', 'theme_prefs'];

    protected $hidden = ['remember_token', 'totp_secret'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'theme_prefs' => 'array',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
        ];
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'user_domain');
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    public function hasTotp(): bool
    {
        // The raw column: reading the decrypted value would throw if APP_KEY changed, and that must fail closed, not open.
        return $this->totp_confirmed_at !== null && ($this->attributes['totp_secret'] ?? null) !== null;
    }

    public function hasTwoFactor(): bool
    {
        // passkeys_exists is present when the list was loaded with withExists('passkeys'), which saves a query per person.
        return $this->hasTotp() || ($this->passkeys_exists ?? $this->passkeys()->exists());
    }

    /** True when this person's role must have a second factor before using the panel. */
    public function requiresTwoFactor(): bool
    {
        return in_array($this->role, config('panel.security.two_factor_required_roles'), true);
    }

    /** Opaque, stable id for passkeys: not the database id and not personal data. */
    public function webauthnHandle(): string
    {
        return hash_hmac('sha256', 'webauthn-user:'.$this->id, (string) config('app.key'), true);
    }

    /** Wipes every second factor and signs the person out everywhere. */
    public function resetTwoFactor(): void
    {
        $this->passkeys()->delete();
        $this->recoveryCodes()->delete();
        $this->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $this->revokeSessions();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER;
    }

    /** Domains this user may see. */
    public function visibleDomains(): Builder
    {
        return $this->isSuperAdmin()
            ? Domain::query()
            : Domain::query()->whereIn('id', $this->domains()->select('domains.id'));
    }

    public function canView(Domain $domain): bool
    {
        if (! $this->active) {
            return false;
        }

        return $this->isSuperAdmin() || $this->domains()->where('domains.id', $domain->id)->exists();
    }

    /** Write access: super admin everywhere, domain manager on assigned domains, read-only never. */
    public function canManage(Domain $domain): bool
    {
        if (! $this->active || $this->role === self::ROLE_READONLY) {
            return false;
        }

        return $this->isSuperAdmin()
            || ($this->role === self::ROLE_MANAGER && $this->domains()->where('domains.id', $domain->id)->exists());
    }

    /** End every browser session of this person (database sessions only; other drivers expire on their own). */
    public function revokeSessions(): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
        }
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER => 'Super admin',
            self::ROLE_MANAGER => 'Domain manager',
            default => 'Read-only',
        };
    }
}
