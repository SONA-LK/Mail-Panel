<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    public const UPDATED_AT = null;

    public const DOMAIN_REMOVE = 'domain.remove';

    public const USER_DELETE = 'user.delete';

    public const USER_DEMOTE = 'user.demote';

    protected $fillable = ['action', 'target', 'payload', 'requested_by', 'requested_by_email', 'status', 'decided_by', 'decided_at', 'expires_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime', 'expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function label(): string
    {
        return match ($this->action) {
            self::DOMAIN_REMOVE => 'Remove domain from the panel',
            self::USER_DELETE => 'Remove super admin',
            self::USER_DEMOTE => 'Take super admin rights away or disable',
            default => $this->action,
        };
    }
}
