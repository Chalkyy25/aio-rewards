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
 * @property int $account_credit_bonus_minor_snapshot
 * @property ?string $preferred_payout_method_snapshot
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
        'amount_minor', 'account_credit_bonus_minor_snapshot', 'preferred_payout_method_snapshot',
        'currency', 'status', 'note',
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
            'account_credit_bonus_minor_snapshot' => 'integer',
            'preferred_payout_method_snapshot' => PayoutMethod::class,
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

    /** Immutable promotional bonus snapshotted at claim. */
    public function accountCreditBonusMinor(): int
    {
        return max(0, (int) $this->account_credit_bonus_minor_snapshot);
    }

    /** Cash reward + snapshotted Account Credit bonus. */
    public function accountCreditTotalMinor(): int
    {
        return (int) $this->amount_minor + $this->accountCreditBonusMinor();
    }

    /**
     * Payout method frozen at claim. Null for historical rewards claimed
     * before this snapshot existed — do not invent a method for those rows.
     */
    public function claimedPayoutMethod(): ?PayoutMethod
    {
        $snap = $this->preferred_payout_method_snapshot;
        if ($snap instanceof PayoutMethod) {
            return $snap;
        }
        if (is_string($snap) && $snap !== '') {
            return PayoutMethod::tryFrom($snap);
        }

        return null;
    }

    public function claimedAsAccountCredit(): bool
    {
        return $this->claimedPayoutMethod() === PayoutMethod::AccountCredit;
    }

    public function claimedAsBankTransfer(): bool
    {
        return $this->claimedPayoutMethod() === PayoutMethod::BankTransfer;
    }

    /**
     * Amount the member should see for this claim:
     * AC claims include the bonus; bank / unknown show cash amount only.
     */
    public function memberFacingAmountMinor(): int
    {
        return $this->claimedAsAccountCredit()
            ? $this->accountCreditTotalMinor()
            : (int) $this->amount_minor;
    }

    public function memberFacingAmountFormatted(): string
    {
        $minor = $this->memberFacingAmountMinor();

        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($minor / 100, 2),
            'eur' => '€'.number_format($minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($minor / 100, 2),
        };
    }

    /**
     * Admin fulfilment routing for this reward.
     * Prefer the claim snapshot; only fall back to live preference for
     * legacy rows where the snapshot was never captured.
     */
    public function fulfilmentPayoutMethod(): ?PayoutMethod
    {
        $claimed = $this->claimedPayoutMethod();
        if ($claimed !== null) {
            return $claimed;
        }

        // Legacy unpaid/paid-before-snapshot rows: payment_method if already paid.
        if (is_string($this->payment_method) && $this->payment_method !== '') {
            $fromPaid = PayoutMethod::tryFrom($this->payment_method);
            if ($fromPaid) {
                return $fromPaid;
            }
        }

        return $this->ambassadorProfile?->payoutProfile?->preferred_method;
    }

    public function accountCreditTotalFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->accountCreditTotalMinor() / 100, 2),
            'eur' => '€'.number_format($this->accountCreditTotalMinor() / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->accountCreditTotalMinor() / 100, 2),
        };
    }

    public function accountCreditBonusFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->accountCreditBonusMinor() / 100, 2),
            'eur' => '€'.number_format($this->accountCreditBonusMinor() / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->accountCreditBonusMinor() / 100, 2),
        };
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
        return $this->fulfilmentPayoutMethod() === PayoutMethod::AccountCredit;
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

    public function memberStatusHeadline(): string
    {
        $method = $this->claimedPayoutMethod();

        return match ($this->status) {
            'pending_approval' => match ($method) {
                PayoutMethod::AccountCredit => 'Pending Account Credit',
                PayoutMethod::BankTransfer => 'Pending Bank Transfer',
                default => 'Pending reward',
            },
            'approved' => match ($method) {
                PayoutMethod::AccountCredit => 'Account Credit ready',
                PayoutMethod::BankTransfer => 'Bank Transfer ready',
                default => 'Awaiting payment',
            },
            'paid' => match ($method ?? PayoutMethod::tryFrom((string) $this->payment_method)) {
                PayoutMethod::AccountCredit => 'Account Credit paid',
                PayoutMethod::BankTransfer => 'Bank Transfer paid',
                default => 'Paid',
            },
            'rejected' => 'Rejected',
            'reversed' => 'Reversed',
            default => ucfirst($this->status),
        };
    }

    public function memberStatusDetail(): ?string
    {
        $method = $this->claimedPayoutMethod();

        return match ($this->status) {
            'pending_approval' => match ($method) {
                PayoutMethod::AccountCredit => $this->accountCreditBonusMinor() > 0
                    ? $this->amountFormatted().' reward + '.$this->accountCreditBonusFormatted().' bonus · Awaiting admin approval'
                    : 'Awaiting admin approval',
                PayoutMethod::BankTransfer => 'Awaiting admin approval',
                default => 'Awaiting admin approval',
            },
            'approved' => match ($method) {
                PayoutMethod::AccountCredit => $this->accountCreditBonusMinor() > 0
                    ? $this->amountFormatted().' reward + '.$this->accountCreditBonusFormatted().' bonus · Ready to be applied'
                    : 'Ready to be applied',
                PayoutMethod::BankTransfer => 'Ready for payout',
                default => 'Ready for payout',
            },
            'paid' => match ($method ?? PayoutMethod::tryFrom((string) $this->payment_method)) {
                PayoutMethod::AccountCredit => $this->accountCreditBonusMinor() > 0
                    ? $this->amountFormatted().' reward + '.$this->accountCreditBonusFormatted().' bonus applied'
                    : 'Applied to your Account Credit',
                PayoutMethod::BankTransfer => 'Sent to your bank',
                default => null,
            },
            default => null,
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
