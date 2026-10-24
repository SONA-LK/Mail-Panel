<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkRow extends Model
{
    protected $fillable = ['batch_id', 'row_number', 'payload', 'status', 'error_message', 'warnings', 'result_secret'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'result_secret' => 'encrypted',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkBatch::class, 'batch_id');
    }
}
