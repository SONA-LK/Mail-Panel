<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Forwarder extends Model
{
    public const TYPE_ADDRESS = 'address';
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_CATCHALL = 'catchall';

    protected $table = 'forwarders_cache';

    protected $fillable = ['source', 'destination', 'type', 'domain_id', 'last_synced_at'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
