<?php

namespace App\Models;

use Database\Factories\AmbassadorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider_username
 * @property ?string $provider_customer_ref
 * @property string $provider_driver_key
 * @property string $referral_code
 * @property bool $flagged_for_review
 * @property ?string $flagged_reason
 * @property Carbon $activated_at
 */
class AmbassadorProfile extends Model
{
    /** @use HasFactory<AmbassadorProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider_username',
        'provider_customer_ref',
        'provider_driver_key',
        'referral_code',
        'flagged_for_review',
        'flagged_reason',
        'activated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flagged_for_review' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Reward, $this> */
    public function rewards(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ReferralConversion, $this> */
    public function referralConversions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReferralConversion::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ReferralAllocation, $this> */
    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReferralAllocation::class);
    }

    public function referralUrl(): string
    {
        return url('/r/'.$this->referral_code);
    }
}
