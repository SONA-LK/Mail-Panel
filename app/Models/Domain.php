<?php

namespace App\Models;

use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    public const NAMING = ['free' => 'Free text', 'first.last' => 'first.last', 'department' => 'Department (letters, digits, dashes)'];
    public const CATCHALL = ['forward' => 'Forward to an address', 'discard' => 'Discard', 'reject' => 'Reject with an error', 'default' => 'cPanel default (main account)'];
    public const FORWARDING = ['any' => 'Any destination', 'internal' => 'Internal only (managed domains)', 'list' => 'Approved list only'];

    protected $fillable = [
        'name', 'purpose', 'default_quota_mb', 'naming_pattern', 'catchall_policy', 'catchall_target',
        'forwarding_policy', 'forwarding_allowlist', 'welcome_enabled', 'welcome_template',
        'suspend_days', 'require_suspend_first', 'auto_delete_suspended',
    ];

    protected function casts(): array
    {
        return [
            'welcome_enabled' => 'boolean',
            'require_suspend_first' => 'boolean',
            'auto_delete_suspended' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_domain');
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function forwarders(): HasMany
    {
        return $this->hasMany(Forwarder::class);
    }

    /** @return list<string> */
    public function allowlist(): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => strtolower(trim($v)),
            preg_split('/[\s,;]+/', (string) $this->forwarding_allowlist) ?: []
        )));
    }

    /** Find the managed domain for an email address (by its part after @). */
    public static function forAddress(string $address): ?self
    {
        $at = strrpos($address, '@');

        return $at === false ? null : self::where('name', strtolower(substr($address, $at + 1)))->first();
    }
}
