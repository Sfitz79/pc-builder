<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Benchmark extends Model
{
    protected $fillable = [
        'cpu_id',
        'gpu_id',
        'game',
        'resolution',
        'settings',
        'fps',
    ];

    protected $casts = [
        'fps' => 'integer',
    ];

    public function cpu(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'cpu_id');
    }

    public function gpu(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'gpu_id');
    }
}
