<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandVersion extends Model
{
    public const UPDATED_AT = null;

    public const KEEP = 15;

    protected $fillable = ['name', 'primary', 'header', 'font', 'allow_palettes', 'logo_path', 'created_by', 'note'];

    protected function casts(): array
    {
        return ['allow_palettes' => 'boolean', 'created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
