<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ROLE_SUPER = 'super_admin';
    public const ROLE_MANAGER = 'domain_manager';
    public const ROLE_READONLY = 'read_only';
    public const ROLES = [self::ROLE_SUPER, self::ROLE_MANAGER, self::ROLE_READONLY];

    protected $fillable = ['name', 'email', 'google_id', 'role', 'active', 'last_login_at'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'user_domain');
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

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER => 'Super admin',
            self::ROLE_MANAGER => 'Domain manager',
            default => 'Read-only',
        };
    }
}
