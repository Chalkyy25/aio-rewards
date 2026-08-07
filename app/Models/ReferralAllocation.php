<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $referral_conversion_id
 * @property int $ambassador_profile_id
 * @property int $cycle_number
 * @property ?int $reward_id
 * @property ?int $active_marker  1 while active, NULL when released
 * @property \Illuminate\Support\Carbon $allocated_at
 * @property ?\Illuminate\Support\Carbon $released_at
 * @property ?string $release_reason
 */
class ReferralAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\ReferralAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'referral_conversion_id', 'ambassador_profile_id', 'cycle_number',
        'reward_id', 'active_marker', 'allocated_at', 'released_at', 'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'active_marker' => 'integer',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReferralConversion, $this> */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(ReferralConversion::class, 'referral_conversion_id');
    }

    /** @return BelongsTo<Reward, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    public function isActive(): bool
    {
        return $this->active_marker !== null && $this->released_at === null;
    }
}
