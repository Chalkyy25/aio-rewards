<?php

namespace App\Domain\Rewards;

use App\Models\RewardMilestoneTier;

/**
 * Immutable snapshot of a member's milestone progression state.
 */
final class MilestoneProgress
{
    /**
     * @param  array<int, RewardMilestoneTier>  $tiers    all active+visible tiers, ordered ascending
     * @param  array<int, array{tier: RewardMilestoneTier, claimable: bool}>  $ladder
     */
    public function __construct(
        public readonly int $cycleNumber,
        public readonly int $eligibleCount,
        public readonly ?RewardMilestoneTier $availableTier,
        public readonly ?RewardMilestoneTier $nextTier,
        public readonly int $availableAmountMinor,
        public readonly int $referralsRemaining,
        public readonly int $bonusBeingBuiltMinor,
        public readonly array $tiers,
        public readonly array $ladder,
    ) {
    }

    public function hasClaim(): bool
    {
        return $this->availableTier !== null;
    }

    public function progressPercent(): int
    {
        $target = $this->nextTier?->threshold ?? $this->availableTier?->threshold;
        if (! $target) {
            return 0;
        }

        return (int) min(100, round(($this->eligibleCount / $target) * 100));
    }
}
