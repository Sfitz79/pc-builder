<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildComponent extends Model
{
    protected $fillable = [
        'build_id',
        'component_id',
        'category',
        'price_snapshot',
    ];

    protected $casts = [
        'price_snapshot' => 'decimal:2',
    ];

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
