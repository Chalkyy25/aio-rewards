<?php

namespace App\Models;

use App\Enums\PayoutMethod;
use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ambassador_profile_id
 * @property ?int $reward_rule_id
 * @property ?int $trigger_conversion_id
 * @property int $milestone_index
 * @property int $amount_minor
 * @property string $currency
 * @property string $status pending_approval|approved|rejected|paid|reversed
 * @property ?Carbon $approved_at
 * @property ?Carbon $paid_at
 * @property ?string $payment_method
 * @property ?string $payment_reference
 * @property ?Carbon $rejected_at
 * @property ?Carbon $reversed_at
 * @property ?int $approved_by_user_id
 * @property ?int $paid_by_user_id
 * @property ?int $rejected_by_user_id
 * @property ?int $reversed_by_user_id
 * @property ?string $note
 */
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    protected $fillable = [
        'ambassador_profile_id', 'reward_rule_id', 'trigger_conversion_id',
        'milestone_tier_id', 'milestone_index', 'cycle_number', 'origin',
        'tier_snapshot', 'idempotency_key', 'reject_disposition',
        'amount_minor', 'currency', 'status', 'note',
        'payment_method', 'payment_reference',
        'funding_compromised_at', 'funding_compromise_reason', 'funding_compromise_conversion_id',
        'account_credit_transaction_id',
        'approved_by_user_id', 'paid_by_user_id', 'rejected_by_user_id', 'reversed_by_user_id',
        'approved_at', 'paid_at', 'rejected_at', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'milestone_index' => 'integer',
            'cycle_number' => 'integer',
            'tier_snapshot' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reversed_at' => 'datetime',
            'funding_compromised_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RewardMilestoneTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(RewardMilestoneTier::class, 'milestone_tier_id');
    }

    /** @return HasMany<ReferralAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReferralAllocation::class, 'reward_id');
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    /** @return BelongsTo<RewardRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(RewardRule::class, 'reward_rule_id');
    }

    /** @return BelongsTo<ReferralConversion, $this> */
    public function triggerConversion(): BelongsTo
    {
        return $this->belongsTo(ReferralConversion::class, 'trigger_conversion_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    /** @return BelongsTo<AccountCreditTransaction, $this> */
    public function accountCreditTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountCreditTransaction::class, 'account_credit_transaction_id');
    }

    public function prefersAccountCredit(): bool
    {
        return $this->ambassadorProfile?->payoutProfile?->preferred_method
            === PayoutMethod::AccountCredit;
    }

    public function isFundingCompromised(): bool
    {
        return $this->funding_compromised_at !== null;
    }

    public function amountFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->amount_minor / 100, 2),
            'eur' => '€'.number_format($this->amount_minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->amount_minor / 100, 2),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending_approval' => 'Pending approval',
            'approved' => 'Awaiting payment',
            'rejected' => 'Rejected',
            'paid' => 'Paid',
            'reversed' => 'Reversed',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending_approval' => 'warning',
            'approved' => 'info',
            'paid' => 'success',
            'rejected' => 'gray',
            'reversed' => 'danger',
            default => 'gray',
        };
    }
}
