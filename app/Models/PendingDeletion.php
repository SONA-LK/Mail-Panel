<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingDeletion extends Model
{
    protected $fillable = ['address', 'domain_id', 'suspended_at', 'delete_after', 'requested_by'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'delete_after' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function isEligible(): bool
    {
        return $this->delete_after->isPast();
    }
}
