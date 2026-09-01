<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoftwareProduct extends Model
{
    protected $fillable = [
        'metenzi_product_id',
        'sku',
        'name',
        'category',
        'platform',
        'description',
        'short_description',
        'retail_price',
        'retail_price_cents',
        'gbp_price',
        'currency',
        'stock',
        'active',
        'warranty_days',
        'image_url',
        'instructions',
        'status',
    ];

    protected $casts = [
        'retail_price' => 'decimal:2',
        'retail_price_cents' => 'integer',
        'gbp_price' => 'decimal:2',
        'stock' => 'integer',
        'active' => 'boolean',
        'warranty_days' => 'integer',
    ];

    public function purchases(): HasMany
    {
        return $this->hasMany(SoftwarePurchase::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('stock', '>', 0);
    }
}
