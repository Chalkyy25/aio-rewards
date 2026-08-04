<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property string $currency
 * @property string $status
 * @property string $fulfilment_status
 * @property ?string $stripe_session_id
 * @property ?string $stripe_payment_intent_id
 * @property ?string $stripe_charge_id
 * @property ?string $attribution_id
 * @property ?string $referral_code_snapshot
 * @property ?int $ambassador_profile_id_snapshot
 * @property ?\Illuminate\Support\Carbon $paid_at
 * @property ?\Illuminate\Support\Carbon $fulfilled_at
 * @property ?int $fulfilled_by_user_id
 */
class Purchase extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'package_id', 'buyer_name', 'buyer_email', 'preferred_username',
        'buyer_phone', 'buyer_telegram', 'delivery_method',
        'amount_minor', 'currency', 'status', 'fulfilment_status',
        'stripe_session_id', 'stripe_payment_intent_id', 'stripe_charge_id',
        'attribution_id', 'referral_code_snapshot', 'ambassador_profile_id_snapshot',
        'terms_accepted_at', 'privacy_accepted_at', 'paid_at', 'fulfilled_at',
        'fulfilled_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
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

    public function orderReference(): string
    {
        return 'AIO-'.strtoupper(substr($this->id, -8));
    }

    public function priceFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->amount_minor / 100, 2),
            'eur' => '€'.number_format($this->amount_minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->amount_minor / 100, 2),
        };
    }
}
