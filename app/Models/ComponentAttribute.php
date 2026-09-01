<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentAttribute extends Model
{
    protected $fillable = [
        'component_id',
        'attribute',
        'value',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
