<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conversion is created when a paid Purchase carries an ambassador snapshot.
 * It transitions from `pending` -> `approved` after the refund window,
 * or `reversed` if a refund/chargeback lands within the window.
 *
 * @property int $id
 * @property string $purchase_id
 * @property int $ambassador_profile_id
 * @property string $referral_code_snapshot
 * @property string $status pending|approved|reversed
 * @property int $amount_minor
 * @property string $currency
 * @property ?\Illuminate\Support\Carbon $pending_until
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?int $approved_by_user_id
 * @property ?\Illuminate\Support\Carbon $reversed_at
 * @property ?int $reversed_by_user_id
 * @property ?string $reversed_reason
 */
class ReferralConversion extends Model
{
    /** @use HasFactory<\Database\Factories\ReferralConversionFactory> */
    use HasFactory;

    protected $fillable = [
        'purchase_id', 'ambassador_profile_id', 'referral_code_snapshot',
        'status', 'amount_minor', 'currency', 'pending_until',
        'approved_at', 'approved_by_user_id',
        'reversed_at', 'reversed_by_user_id', 'reversed_reason',
    ];

    protected function casts(): array
    {
        return [
            'pending_until' => 'datetime',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    public function isRipeForApproval(): bool
    {
        return $this->status === 'pending'
            && $this->pending_until !== null
            && $this->pending_until->isPast();
    }
}
