<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    protected $table = 'mailboxes_cache';

    protected $fillable = [
        'address', 'local_part', 'domain_id', 'quota_mb', 'used_mb',
        'suspended_login', 'suspended_incoming', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'suspended_login' => 'boolean',
            'suspended_incoming' => 'boolean',
            'used_mb' => 'float',
            'last_synced_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function usagePercent(): ?int
    {
        return $this->quota_mb ? (int) round($this->used_mb / $this->quota_mb * 100) : null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_login || $this->suspended_incoming;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->suspended_login && $this->suspended_incoming => 'Suspended',
            $this->suspended_login => 'Login blocked',
            $this->suspended_incoming => 'Incoming blocked',
            default => 'Active',
        };
    }

    public function forwardersTo()
    {
        return Forwarder::where('destination', $this->address)->get();
    }
}
