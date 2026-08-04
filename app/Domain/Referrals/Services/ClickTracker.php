<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\DTOs\AttributionContext;
use App\Domain\Referrals\Support\BotFilter;
use App\Models\AmbassadorProfile;
use App\Models\ReferralClick;
use Illuminate\Support\Str;

/**
 * Records a click and returns the new (or reused) attribution ULID.
 *
 * Also implements the first-touch guard: the attribution cookie is only
 * *set* when the visitor doesn't already carry a valid one.
 */
class ClickTracker
{
    public function __construct(private readonly BotFilter $bots) {}

    public function record(AmbassadorProfile $ambassador, AttributionContext $ctx): ReferralClick
    {
        $isBot = $this->bots->isBot($ctx->userAgent);

        return ReferralClick::create([
            'ambassador_profile_id' => $ambassador->id,
            'referral_code_snapshot' => $ambassador->referral_code,
            'attribution_id' => (string) Str::ulid(),
            'ip_hash' => $this->hashIp($ctx->ip),
            'user_agent' => $ctx->userAgent ? substr($ctx->userAgent, 0, 512) : null,
            'referer_url' => $ctx->refererUrl ? substr($ctx->refererUrl, 0, 512) : null,
            'utm_source' => $ctx->utmSource,
            'utm_medium' => $ctx->utmMedium,
            'utm_campaign' => $ctx->utmCampaign,
            'is_bot' => $isBot,
            'created_at' => now(),
        ]);
    }

    /**
     * Non-reversible IP hash keyed by APP_KEY. Same IP always hashes to the
     * same value so rate-limiting and dedup queries still work, but the raw
     * IP is never stored.
     */
    public function hashIp(string $ip): string
    {
        $key = (string) config('app.key');
        $secret = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        return hash_hmac('sha256', $ip, $secret);
    }
}
