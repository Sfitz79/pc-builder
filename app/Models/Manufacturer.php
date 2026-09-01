<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manufacturer extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo',
        'website',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(Component::class);
    }
}
