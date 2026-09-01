<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoftwarePurchase extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_BACKORDER = 'backorder';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'owner_token',
        'product_id',
        'sku',
        'product_name',
        'amount_gbp',
        'currency',
        'status',
        'customer_name',
        'customer_email',
        'paypal_order_id',
        'paypal_capture_id',
        'metenzi_order_id',
        'metenzi_status',
        'keys',
        'notes',
        'last_webhook_event',
        'paid_at',
        'fulfilled_at',
    ];

    protected $casts = [
        'amount_gbp' => 'decimal:2',
        'keys' => 'encrypted:array',
        'paid_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class);
    }

    public function isOwnedByToken(string $token): bool
    {
        return $this->owner_token !== null && hash_equals($this->owner_token, $token);
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED && $this->keys !== null && $this->keys !== [];
    }
}
