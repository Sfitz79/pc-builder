<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Build extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'user_id',
        'owner_token',
        'name',
        'purpose',
        'resolution',
        'budget',
        'total_price',
        'performance_score',
        'compatibility_checks',
        'public',
        'share_slug',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'total_price' => 'decimal:2',
        'performance_score' => 'integer',
        'compatibility_checks' => 'array',
        'public' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class, 'build_components')
            ->withPivot(['category', 'price_snapshot'])
            ->withTimestamps();
    }

    public function selectedComponents(): BelongsToMany
    {
        return $this->belongsToMany(Component::class, 'build_components')
            ->withPivot(['category', 'price_snapshot'])
            ->withTimestamps()
            ->orderBy('build_components.category');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOwnerToken(Builder $query, string $token): Builder
    {
        return $query->where('owner_token', $token);
    }

    public function scopeByShareSlug(Builder $query, string $slug): Builder
    {
        return $query->where('share_slug', $slug);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    public function isOwnedByToken(string $token): bool
    {
        return $this->owner_token !== null && hash_equals($this->owner_token, $token);
    }

    public function getBuildCostAttribute(): float
    {
        return (float) $this->components->sum(fn ($component) => (float) $component->pivot->price_snapshot);
    }

    public function shareUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => url("/build/{$this->share_slug}")
        );
    }

    public function formattedTotal(): Attribute
    {
        return Attribute::get(
            fn (): string => '£' . number_format((float) $this->total_price, 0)
        );
    }

    public function recalculateTotal(): void
    {
        $this->total_price = $this->getBuildCostAttribute();
        $this->save();
    }
}
