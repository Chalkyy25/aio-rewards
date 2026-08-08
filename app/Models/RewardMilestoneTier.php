<?php

namespace App\Models;

use Database\Factories\RewardMilestoneTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $threshold
 * @property int $total_reward_amount_minor
 * @property int $bonus_amount_minor
 * @property int $account_credit_bonus_minor
 * @property string $currency
 * @property string $title
 * @property ?string $description
 * @property int $display_order
 * @property bool $is_active
 * @property bool $is_visible
 * @property bool $is_claimable
 */
class RewardMilestoneTier extends Model
{
    /** @use HasFactory<RewardMilestoneTierFactory> */
    use HasFactory;

    protected $fillable = [
        'threshold', 'total_reward_amount_minor', 'bonus_amount_minor',
        'account_credit_bonus_minor',
        'currency', 'title', 'description', 'display_order',
        'is_active', 'is_visible', 'is_claimable',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'total_reward_amount_minor' => 'integer',
            'bonus_amount_minor' => 'integer',
            'account_credit_bonus_minor' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'is_claimable' => 'boolean',
        ];
    }

    public function amountFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->total_reward_amount_minor / 100, 0),
            'eur' => '€'.number_format($this->total_reward_amount_minor / 100, 0),
            default => strtoupper($this->currency).' '.number_format($this->total_reward_amount_minor / 100, 0),
        };
    }

    public function bonusFormatted(): string
    {
        return '£'.number_format($this->bonus_amount_minor / 100, 0);
    }

    public function accountCreditBonusFormatted(): string
    {
        return '£'.number_format($this->account_credit_bonus_minor / 100, 0);
    }

    /** Cash reward + this tier's Account Credit promotional bonus. */
    public function accountCreditTotalMinor(): int
    {
        return (int) $this->total_reward_amount_minor + (int) $this->account_credit_bonus_minor;
    }

    public function accountCreditTotalFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->accountCreditTotalMinor() / 100, 0),
            'eur' => '€'.number_format($this->accountCreditTotalMinor() / 100, 0),
            default => strtoupper($this->currency).' '.number_format($this->accountCreditTotalMinor() / 100, 0),
        };
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'tier_id' => $this->id,
            'threshold' => $this->threshold,
            'total_reward_amount_minor' => $this->total_reward_amount_minor,
            'bonus_amount_minor' => $this->bonus_amount_minor,
            'account_credit_bonus_minor' => $this->account_credit_bonus_minor,
            'currency' => $this->currency,
            'title' => $this->title,
        ];
    }
}
