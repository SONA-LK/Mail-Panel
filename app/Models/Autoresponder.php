<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Autoresponder extends Model
{
    protected $table = 'autoresponders_cache';

    protected $fillable = ['address', 'domain_id', 'subject', 'start_at', 'stop_at', 'last_synced_at'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'stop_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }
}
