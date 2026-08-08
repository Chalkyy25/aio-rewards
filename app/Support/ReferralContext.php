<?php

namespace App\Support;

use App\Domain\Referrals\AttributionCookie;
use App\Models\AmbassadorProfile;
use Illuminate\Http\Request;

/**
 * Resolves the referring ambassador's public display name (or null) from
 * the first-touch attribution cookie set by /r/{code}. Cached per request
 * so the landing page can render the personalised banner without repeated
 * DB lookups.
 */
final class ReferralContext
{
    /** @var array<string, ?string> */
    private static array $cache = [];

    public static function referringName(?Request $request = null): ?string
    {
        $request ??= request();
        $payload = app(AttributionCookie::class)->read($request);
        if ($payload === null) {
            return null;
        }

        $cacheKey = $payload['attribution_id'];
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $profile = AmbassadorProfile::with('user:id,name')
            ->where('referral_code', $payload['code'])
            ->first();

        return self::$cache[$cacheKey] = $profile?->user?->name;
    }
}
