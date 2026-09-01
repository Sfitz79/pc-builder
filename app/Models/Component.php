<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Component extends Model
{
    protected $fillable = [
        'category_id',
        'manufacturer_id',
        'name',
        'slug',
        'sku',
        'description',
        'price',
        'currency',
        'socket',
        'wattage',
        'stock',
        'active',
        'specs',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'wattage' => 'integer',
        'stock' => 'integer',
        'active' => 'boolean',
        'specs' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ComponentAttribute::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ComponentImage::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function benchmarks(): HasMany
    {
        return $this->hasMany(Benchmark::class, 'gpu_id');
    }

    public function builds(): BelongsToMany
    {
        return $this->belongsToMany(Build::class, 'build_components')
            ->withPivot(['category', 'price_snapshot'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->whereHas('category', fn (Builder $q) => $q->where('slug', $category));
    }

    public function getPrimaryImageAttribute(): ?string
    {
        return $this->images()
            ->orderBy('sort_order')
            ->value('image');
    }

    public function formattedPrice(): Attribute
    {
        return Attribute::get(
            fn (): string => '£' . number_format((float) $this->price, 0)
        );
    }

    public function inStock(): Attribute
    {
        return Attribute::get(fn (): bool => $this->stock > 0);
    }

    public function tags(): Attribute
    {
        return Attribute::get(function (): ?string {
            $specs = $this->specs ?? [];
            $parts = [];

            if (isset($specs['cores'], $specs['threads'])) {
                $parts[] = $specs['cores'] . ' Core / ' . $specs['threads'] . ' Thread';
            }

            foreach (['memory', 'capacity', 'speed'] as $key) {
                if (isset($specs[$key])) {
                    $parts[] = $specs[$key];
                }
            }

            return $parts !== [] ? implode(' / ', $parts) : null;
        });
    }
}
