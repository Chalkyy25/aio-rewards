<?php

namespace App\Models;

use Database\Factories\ReferralClickFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ambassador_profile_id
 * @property string $referral_code_snapshot
 * @property string $attribution_id
 * @property string $ip_hash
 * @property ?string $user_agent
 * @property ?string $referer_url
 * @property ?string $utm_source
 * @property ?string $utm_medium
 * @property ?string $utm_campaign
 * @property bool $is_bot
 * @property Carbon $created_at
 */
class ReferralClick extends Model
{
    /** @use HasFactory<ReferralClickFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'ambassador_profile_id',
        'referral_code_snapshot',
        'attribution_id',
        'ip_hash',
        'user_agent',
        'referer_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'is_bot',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AmbassadorProfile, $this>
     */
    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class, 'ambassador_profile_id');
    }
}
