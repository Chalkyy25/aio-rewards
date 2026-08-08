<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted idempotency marker for a milestone-unlock notification.
 *
 * @property int $id
 * @property int $ambassador_profile_id
 * @property int $user_id
 * @property int $cycle_number
 * @property int $milestone_tier_id
 * @property string $idempotency_key
 * @property string $status
 * @property array<string, mixed>|null $tier_snapshot
 * @property ?string $failure_class
 * @property ?\Illuminate\Support\Carbon $queued_at
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property ?\Illuminate\Support\Carbon $failed_at
 */
class MilestoneUnlockNotification extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ambassador_profile_id',
        'user_id',
        'cycle_number',
        'milestone_tier_id',
        'idempotency_key',
        'status',
        'tier_snapshot',
        'failure_class',
        'queued_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'tier_snapshot' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RewardMilestoneTier, $this> */
    public function milestoneTier(): BelongsTo
    {
        return $this->belongsTo(RewardMilestoneTier::class, 'milestone_tier_id');
    }

    public static function buildKey(int $profileId, int $cycleNumber, int $tierId): string
    {
        return sprintf('member:%d:cycle:%d:tier:%d:unlocked', $profileId, $cycleNumber, $tierId);
    }
}
