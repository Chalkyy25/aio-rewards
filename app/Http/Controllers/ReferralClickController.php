<?php

namespace App\Http\Controllers;

use App\Domain\Referrals\DTOs\AttributionContext;
use App\Domain\Referrals\Services\ClickTracker;
use App\Models\AmbassadorProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;

class ReferralClickController extends Controller
{
    public function __construct(private readonly ClickTracker $tracker) {}

    public function __invoke(Request $request, string $code): Response|RedirectResponse
    {
        $code = strtoupper(trim($code));

        // Per-IP + per-code rate limits, before we hit the DB.
        $ip = $request->ip() ?? '0.0.0.0';
        $ipKey = 'ref-click|ip:'.$ip;
        $codeKey = 'ref-click|code:'.$code;

        $ipLimit = (int) config('referrals.click_rate_limits.per_ip_per_min', 60);
        $codeLimit = (int) config('referrals.click_rate_limits.per_code_per_min', 600);

        if (RateLimiter::tooManyAttempts($ipKey, $ipLimit)
            || RateLimiter::tooManyAttempts($codeKey, $codeLimit)) {
            return response(view('public.referral-unavailable', ['reason' => 'busy']), 429);
        }

        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($codeKey, 60);

        // Resolve ambassador. Only ACTIVE + unflagged users produce a valid link.
        /** @var AmbassadorProfile|null $ambassador */
        $ambassador = AmbassadorProfile::query()
            ->with('user')
            ->where('referral_code', $code)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $ambassador) {
            // Do not leak whether the code has ever existed.
            return response(view('public.referral-unavailable', ['reason' => 'notfound']), 404);
        }

        $ctx = new AttributionContext(
            referralCode: $code,
            ip: $ip,
            userAgent: $request->userAgent(),
            refererUrl: $request->headers->get('referer'),
            utmSource: $this->trim($request->query('utm_source')),
            utmMedium: $this->trim($request->query('utm_medium')),
            utmCampaign: $this->trim($request->query('utm_campaign')),
        );

        $click = $this->tracker->record($ambassador, $ctx);

        $redirect = redirect((string) config('referrals.default_redirect_after_click', '/'));

        // First-touch attribution: only set the cookie if a valid one isn't
        // already present. Cookie is encrypted by Laravel's cookie middleware.
        if (! $this->hasValidAttributionCookie($request)) {
            $cookieName = (string) config('referrals.cookie.name', 'aior_ref');
            $days = (int) config('referrals.cookie.days', 30);
            $payload = json_encode([
                'v' => 1,
                'code' => $code,
                'attribution_id' => $click->attribution_id,
                'set_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR);

            $redirect->cookie(Cookie::make(
                name: $cookieName,
                value: $payload,
                minutes: $days * 24 * 60,
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: 'Lax',
            ));
        }

        return $redirect;
    }

    private function hasValidAttributionCookie(Request $request): bool
    {
        $name = (string) config('referrals.cookie.name', 'aior_ref');
        $raw = $request->cookie($name);

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        // Laravel's cookie middleware has already decrypted the payload for us.
        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return is_array($payload)
            && ! empty($payload['code'])
            && ! empty($payload['attribution_id']);
    }

    private function trim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = substr(trim($value), 0, 128);

        return $v === '' ? null : $v;
    }
}
