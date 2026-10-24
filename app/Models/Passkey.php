<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Passkey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'name', 'credential_id', 'record', 'last_used_at'];

    protected $hidden = ['record'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
