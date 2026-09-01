<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'component_id',
        'price',
        'currency',
        'recorded_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
