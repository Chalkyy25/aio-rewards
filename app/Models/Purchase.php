<?php

namespace App\Models;

use App\Domain\Fulfilment\OrderStatus;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $package_id
 * @property string $buyer_name
 * @property string $buyer_email
 * @property string $preferred_username
 * @property ?string $buyer_phone
 * @property ?string $buyer_telegram
 * @property string $delivery_method
 * @property int $amount_minor
 * @property int $account_credit_applied_minor
 * @property ?int $external_amount_minor
 * @property int $external_refunded_minor
 * @property int $account_credit_restored_minor
 * @property string $currency
 * @property string $status
 * @property string $fulfilment_status
 * @property ?string $stripe_session_id
 * @property ?string $stripe_payment_intent_id
 * @property ?string $stripe_charge_id
 * @property ?string $attribution_id
 * @property ?string $referral_code_snapshot
 * @property ?int $ambassador_profile_id_snapshot
 * @property ?Carbon $paid_at
 * @property ?Carbon $payment_received_at
 * @property ?Carbon $setup_started_at
 * @property ?Carbon $awaiting_customer_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $cancelled_at
 * @property ?Carbon $refunded_at
 * @property ?Carbon $fulfilled_at
 * @property ?int $fulfilled_by_user_id
 * @property ?string $provisioned_username_enc
 * @property ?string $provisioned_password_enc
 * @property ?Carbon $provisioned_expires_on
 * @property ?string $setup_instructions_md
 * @property ?array $download_links
 * @property ?string $fulfilment_notes
 * @property ?string $customer_view_token
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'package_id', 'buyer_name', 'buyer_email', 'preferred_username',
        'buyer_phone', 'buyer_telegram', 'delivery_method',
        'amount_minor', 'account_credit_applied_minor', 'external_amount_minor',
        'external_refunded_minor', 'account_credit_restored_minor',
        'currency', 'status', 'fulfilment_status',
        'stripe_session_id', 'stripe_payment_intent_id', 'stripe_charge_id', 'active_payment_attempt_id',
        'attribution_id', 'referral_code_snapshot', 'ambassador_profile_id_snapshot',
        'terms_accepted_at', 'privacy_accepted_at', 'paid_at', 'fulfilled_at',
        'fulfilled_by_user_id',
        // Phase 4 fulfilment.
        'provisioned_username_enc', 'provisioned_password_enc', 'provisioned_expires_on',
        'setup_instructions_md', 'download_links', 'fulfilment_notes', 'customer_view_token',
        'payment_received_at', 'setup_started_at', 'awaiting_customer_at',
        'completed_at', 'cancelled_at', 'refunded_at',
        'payment_email_sent_at', 'completed_email_sent_at',
    ];

    protected $hidden = [
        'provisioned_password_enc',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'account_credit_applied_minor' => 'integer',
            'external_amount_minor' => 'integer',
            'external_refunded_minor' => 'integer',
            'account_credit_restored_minor' => 'integer',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'payment_received_at' => 'datetime',
            'setup_started_at' => 'datetime',
            'awaiting_customer_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'payment_email_sent_at' => 'datetime',
            'completed_email_sent_at' => 'datetime',
            'provisioned_expires_on' => 'date',
            'provisioned_username_enc' => 'encrypted',
            'provisioned_password_enc' => 'encrypted',
            'download_links' => 'array',
        ];
    }

    /** @return HasOne<AccountCreditReservation, $this> */
    public function accountCreditReservation(): HasOne
    {
        return $this->hasOne(AccountCreditReservation::class);
    }

    /** @return HasMany<PurchasePaymentAttempt, $this> */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PurchasePaymentAttempt::class);
    }

    /** @return BelongsTo<PurchasePaymentAttempt, $this> */
    public function activePaymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PurchasePaymentAttempt::class, 'active_payment_attempt_id');
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorSnapshot(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class, 'ambassador_profile_id_snapshot');
    }

    /** @return HasOne<ReferralConversion, $this> */
    public function referralConversion(): HasOne
    {
        return $this->hasOne(ReferralConversion::class, 'purchase_id');
    }

    public function orderReference(): string
    {
        return 'AIO-'.strtoupper(substr($this->id, -8));
    }

    public function priceFormatted(): string
    {
        return $this->formatAmountMinor((int) $this->amount_minor);
    }

    /**
     * Format a stored minor amount using this purchase's currency.
     * Display helper only — does not recalculate from package pricing.
     */
    public function formatAmountMinor(int $minor): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($minor / 100, 2),
            'eur' => '€'.number_format($minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($minor / 100, 2),
        };
    }

    /**
     * Account Credit applied on this purchase (null/legacy treated as 0).
     */
    public function accountCreditAppliedForDisplay(): int
    {
        return (int) ($this->account_credit_applied_minor ?? 0);
    }

    public function showsAccountCreditRow(): bool
    {
        return $this->accountCreditAppliedForDisplay() > 0;
    }

    /**
     * Whether the purchase has an immutable card/external split amount.
     * Null = legacy purchase without split data — do not invent a card row.
     */
    public function hasExternalAmountSplit(): bool
    {
        return $this->external_amount_minor !== null;
    }

    /**
     * Show Card payment when split data exists and the external amount is > 0.
     * Hidden for full Account Credit (external = 0) and for legacy (null).
     */
    public function showsCardPaymentRow(): bool
    {
        return $this->hasExternalAmountSplit() && (int) $this->external_amount_minor > 0;
    }

    /**
     * Human-friendly status label, tolerant of legacy enum values.
     */
    public function statusLabel(): string
    {
        $enum = OrderStatus::tryFrom((string) $this->fulfilment_status);

        return $enum?->label() ?? match ($this->fulfilment_status) {
            'unfulfilled' => 'Awaiting payment',
            'fulfilled' => 'Completed',
            default => 'Unknown',
        };
    }

    public function statusColor(): string
    {
        $enum = OrderStatus::tryFrom((string) $this->fulfilment_status);
        if ($enum) {
            return $enum->color();
        }

        return match ($this->fulfilment_status) {
            'fulfilled' => 'success',
            default => 'gray',
        };
    }

    public function currentStatus(): ?OrderStatus
    {
        return OrderStatus::tryFrom((string) $this->fulfilment_status);
    }
}
