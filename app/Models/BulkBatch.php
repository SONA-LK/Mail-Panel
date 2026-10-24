<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkBatch extends Model
{
    protected $fillable = ['user_id', 'type', 'original_name', 'status', 'total_rows', 'done_rows', 'failed_rows'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BulkRow::class, 'batch_id');
    }

    public function validCount(): int
    {
        return $this->rows()->whereIn('status', ['valid', 'queued', 'done', 'failed'])->count();
    }

    public function invalidCount(): int
    {
        return $this->rows()->where('status', 'invalid')->count();
    }
}
