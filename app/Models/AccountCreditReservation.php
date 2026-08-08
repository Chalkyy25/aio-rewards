<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Soft hold of Account Credit against a pending purchase.
 *
 * @property int $id
 * @property int $ambassador_profile_id
 * @property string $purchase_id ULID
 * @property int $amount_minor
 * @property string $currency
 * @property string $status pending|committed|released|expired
 * @property ?Carbon $expires_at
 * @property ?Carbon $committed_at
 * @property ?Carbon $released_at
 * @property string $idempotency_key
 */
class AccountCreditReservation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_RELEASED = 'released';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'ambassador_profile_id',
        'purchase_id',
        'amount_minor',
        'currency',
        'status',
        'expires_at',
        'committed_at',
        'released_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
