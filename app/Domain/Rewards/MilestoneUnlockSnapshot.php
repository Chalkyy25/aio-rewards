<?php

namespace App\Domain\Rewards;

/**
 * Safe, serialisable snapshot for milestone-unlock notifications.
 * Contains no buyer PII, conversion metadata, or payment secrets.
 */
final class MilestoneUnlockSnapshot
{
    public function __construct(
        public readonly int $ambassadorProfileId,
        public readonly int $userId,
        public readonly int $cycleNumber,
        public readonly int $tierId,
        public readonly int $threshold,
        public readonly int $totalRewardAmountMinor,
        public readonly int $bonusAmountMinor,
        public readonly string $currency,
        public readonly string $title,
        public readonly int $eligibleCount,
        public readonly string $memberDisplayName,
        public readonly ?int $nextThreshold = null,
        public readonly ?int $nextTotalRewardAmountMinor = null,
        public readonly ?int $nextBonusAmountMinor = null,
        public readonly ?string $nextTitle = null,
        public readonly string $idempotencyKey = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ambassador_profile_id' => $this->ambassadorProfileId,
            'user_id' => $this->userId,
            'cycle_number' => $this->cycleNumber,
            'tier_id' => $this->tierId,
            'threshold' => $this->threshold,
            'total_reward_amount_minor' => $this->totalRewardAmountMinor,
            'bonus_amount_minor' => $this->bonusAmountMinor,
            'currency' => $this->currency,
            'title' => $this->title,
            'eligible_count' => $this->eligibleCount,
            'member_display_name' => $this->memberDisplayName,
            'next_threshold' => $this->nextThreshold,
            'next_total_reward_amount_minor' => $this->nextTotalRewardAmountMinor,
            'next_bonus_amount_minor' => $this->nextBonusAmountMinor,
            'next_title' => $this->nextTitle,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    public function rewardAmountFormatted(): string
    {
        return self::formatMoney($this->totalRewardAmountMinor, $this->currency);
    }

    public function bonusAmountFormatted(): string
    {
        return self::formatMoney($this->bonusAmountMinor, $this->currency);
    }

    public function nextRewardAmountFormatted(): ?string
    {
        if ($this->nextTotalRewardAmountMinor === null) {
            return null;
        }

        return self::formatMoney($this->nextTotalRewardAmountMinor, $this->currency);
    }

    public function nextBonusAmountFormatted(): ?string
    {
        if ($this->nextBonusAmountMinor === null) {
            return null;
        }

        return self::formatMoney($this->nextBonusAmountMinor, $this->currency);
    }

    public function firstName(): string
    {
        $name = trim($this->memberDisplayName);
        if ($name === '') {
            return 'there';
        }

        return explode(' ', $name)[0];
    }

    private static function formatMoney(int $minor, string $currency): string
    {
        return match (strtolower($currency)) {
            'gbp' => '£'.number_format($minor / 100, 0),
            'eur' => '€'.number_format($minor / 100, 0),
            default => strtoupper($currency).' '.number_format($minor / 100, 0),
        };
    }
}
