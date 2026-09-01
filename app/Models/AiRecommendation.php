<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRecommendation extends Model
{
    protected $fillable = [
        'user_id',
        'budget',
        'purpose',
        'resolution',
        'recommendation',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'recommendation' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
