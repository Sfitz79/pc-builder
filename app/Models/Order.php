<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'user_id',
        'owner_token',
        'build_id',
        'status',
        'paypal_order_id',
        'paypal_capture_id',
        'paypal_invoice_id',
        'currency',
        'customer_name',
        'customer_email',
        'parts_total',
        'build_delivery',
        'subtotal',
        'paypal_fee',
        'total',
        'payload',
        'paid_at',
    ];

    protected $casts = [
        'parts_total' => 'decimal:2',
        'build_delivery' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'paypal_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'payload' => 'array',
        'paid_at' => 'datetime',
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

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    public function scopeForOwnerToken($query, string $token)
    {
        return $query->where('owner_token', $token);
    }

    public function isOwnedByToken(string $token): bool
    {
        return $this->owner_token !== null && hash_equals($this->owner_token, $token);
    }
}
