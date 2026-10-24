<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailFilter extends Model
{
    protected $table = 'filters_cache';

    protected $fillable = ['domain_id', 'scope', 'address', 'name', 'rule_summary', 'last_synced_at'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
