<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
