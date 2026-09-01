<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'component_id',
        'quantity',
        'low_stock_threshold',
        'in_stock',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'in_stock' => 'boolean',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
