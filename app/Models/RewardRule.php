<?php

namespace App\Models;

use Database\Factories\RewardRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable rule that turns approved ReferralConversions into
 * Reward records. Only 'every_n_cash' is evaluated today; the other
 * kinds are placeholders for future work.
 *
 * @property int $id
 * @property string $name
 * @property string $kind
 * @property int $trigger_count
 * @property int $amount_minor
 * @property string $currency
 * @property ?int $percentage_bps
 * @property bool $is_active
 * @property int $sort_order
 */
class RewardRule extends Model
{
    /** @use HasFactory<RewardRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'kind', 'trigger_count', 'amount_minor', 'currency',
        'percentage_bps', 'is_active', 'sort_order',
    ];

    protected static function booted(): void
    {
        // Launch guard: legacy every_n_cash must never become active again.
        static::saving(function (RewardRule $rule): void {
            if ($rule->kind === 'every_n_cash') {
                $rule->is_active = false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'trigger_count' => 'integer',
            'amount_minor' => 'integer',
            'percentage_bps' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Reward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    public function amountFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->amount_minor / 100, 2),
            'eur' => '€'.number_format($this->amount_minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->amount_minor / 100, 2),
        };
    }

    public function summary(): string
    {
        return match ($this->kind) {
            'every_n_cash' => sprintf('Every %d approved referrals → %s',
                $this->trigger_count, $this->amountFormatted()),
            'percentage' => sprintf('%.2f%% of sale value',
                ($this->percentage_bps ?? 0) / 100),
            'lifetime_revenue' => 'Lifetime revenue bonus',
            default => ucfirst(str_replace('_', ' ', $this->kind)),
        };
    }
}
