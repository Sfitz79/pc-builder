<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentImage extends Model
{
    protected $fillable = [
        'component_id',
        'image',
        'alt',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
