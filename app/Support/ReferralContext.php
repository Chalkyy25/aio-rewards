<?php

namespace App\Support;

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
        $cookieName = (string) config('referrals.cookie.name', 'aior_ref');
        $raw = $request->cookie($cookieName);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (array_key_exists($raw, self::$cache)) {
            return self::$cache[$raw];
        }

        $payload = json_decode($raw, true);
        $code = is_array($payload) ? ($payload['code'] ?? null) : null;
        if (! is_string($code) || $code === '') {
            return self::$cache[$raw] = null;
        }

        $profile = AmbassadorProfile::with('user:id,name')
            ->where('referral_code', $code)
            ->first();

        return self::$cache[$raw] = $profile?->user?->name;
    }
}
