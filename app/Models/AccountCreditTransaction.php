<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable Account Credit ledger row.
 *
 * @property int $id
 * @property int $ambassador_profile_id
 * @property int $amount_minor signed minor units (credit +, debit -)
 * @property string $currency
 * @property string $direction credit|debit
 * @property string $source
 * @property ?int $reward_id
 * @property ?string $purchase_id ULID
 * @property ?int $actor_user_id
 * @property string $origin
 * @property string $idempotency_key
 * @property ?string $reference
 * @property ?string $note
 * @property Carbon $created_at
 */
class AccountCreditTransaction extends Model
{
    public const UPDATED_AT = null;

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    public const SOURCE_REWARD_FULFILMENT = 'reward_fulfilment';

    public const SOURCE_REWARD_BONUS = 'reward_bonus';

    public const SOURCE_PURCHASE_REDEMPTION = 'purchase_redemption';

    public const SOURCE_CREDIT_RESTORATION = 'credit_restoration';

    public const SOURCE_ADMIN_ADJUSTMENT = 'admin_adjustment';

    public const SOURCE_REVERSAL = 'reversal';

    protected $fillable = [
        'ambassador_profile_id',
        'amount_minor',
        'currency',
        'direction',
        'source',
        'reward_id',
        'purchase_id',
        'actor_user_id',
        'origin',
        'idempotency_key',
        'reference',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    /** @return BelongsTo<Reward, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function amountFormatted(): string
    {
        $abs = abs($this->amount_minor);
        $formatted = match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($abs / 100, 2),
            'eur' => '€'.number_format($abs / 100, 2),
            default => strtoupper($this->currency).' '.number_format($abs / 100, 2),
        };

        return ($this->amount_minor < 0 ? '−' : '+').$formatted;
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_REWARD_FULFILMENT => 'Reward Credit',
            self::SOURCE_REWARD_BONUS => 'Milestone Bonus',
            self::SOURCE_PURCHASE_REDEMPTION => 'Package Purchase',
            self::SOURCE_CREDIT_RESTORATION => 'Credit Restoration',
            self::SOURCE_ADMIN_ADJUSTMENT => 'Admin adjustment',
            self::SOURCE_REVERSAL => 'Reversal',
            default => ucfirst(str_replace('_', ' ', $this->source)),
        };
    }
}
