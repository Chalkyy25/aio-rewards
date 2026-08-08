<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached Account Credit balance. Ledger remains authoritative.
 *
 * @property int $id
 * @property int $ambassador_profile_id
 * @property int $balance_minor
 * @property string $currency
 */
class AccountCreditBalance extends Model
{
    protected $fillable = [
        'ambassador_profile_id',
        'balance_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    public function balanceFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->balance_minor / 100, 2),
            'eur' => '€'.number_format($this->balance_minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->balance_minor / 100, 2),
        };
    }
}
