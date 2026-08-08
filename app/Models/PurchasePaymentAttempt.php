<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Immutable Stripe payment attempt for a Purchase.
 *
 * @property int $id
 * @property string $purchase_id
 * @property ?string $stripe_session_id
 * @property string $cancel_token
 * @property int $package_amount_minor
 * @property int $account_credit_applied_minor
 * @property int $external_amount_minor
 * @property string $currency
 * @property string $status
 * @property ?Carbon $completed_at
 * @property ?Carbon $superseded_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $expired_at
 */
class PurchasePaymentAttempt extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'purchase_id',
        'stripe_session_id',
        'cancel_token',
        'package_amount_minor',
        'account_credit_applied_minor',
        'external_amount_minor',
        'currency',
        'status',
        'completed_at',
        'superseded_at',
        'cancelled_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'package_amount_minor' => 'integer',
            'account_credit_applied_minor' => 'integer',
            'external_amount_minor' => 'integer',
            'completed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public static function makeCancelToken(): string
    {
        return Str::random(48);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
