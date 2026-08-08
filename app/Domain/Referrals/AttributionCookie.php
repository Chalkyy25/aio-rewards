<?php

namespace App\Domain\Referrals;

use App\Models\AmbassadorProfile;
use App\Models\ReferralClick;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Secure first-touch referral attribution cookie.
 *
 * Relies on Laravel's encrypted cookie middleware (aior_ref must NOT be
 * listed in EncryptCookies exceptions). Payload is also validated against
 * ReferralClick rows so forged code/attribution pairs are rejected.
 */
class AttributionCookie
{
    public const PAYLOAD_VERSION = 1;

    /**
     * @return array{code:string,attribution_id:string,set_at:string,v:int}|null
     */
    public function read(Request $request): ?array
    {
        $name = (string) config('referrals.cookie.name', 'aior_ref');
        $raw = $request->cookie($name);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        return $this->validatePayload($payload);
    }

    public function hasValid(Request $request): bool
    {
        return $this->read($request) !== null;
    }

    public function make(string $code, string $attributionId, ?Carbon $setAt = null): SymfonyCookie
    {
        $name = (string) config('referrals.cookie.name', 'aior_ref');
        $days = (int) config('referrals.cookie.days', 30);
        $payload = json_encode([
            'v' => self::PAYLOAD_VERSION,
            'code' => strtoupper(trim($code)),
            'attribution_id' => $attributionId,
            'set_at' => ($setAt ?? now())->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return Cookie::make(
            name: $name,
            value: $payload,
            minutes: $days * 24 * 60,
            path: '/',
            secure: request()?->isSecure() ?? false,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /**
     * Resolve ambassador id from a validated cookie payload.
     *
     * @param array{code:string,attribution_id:string,set_at:string,v:int} $payload
     */
    public function ambassadorProfileId(array $payload): ?int
    {
        return AmbassadorProfile::query()
            ->where('referral_code', $payload['code'])
            ->value('id');
    }

    /**
     * @param array<mixed> $payload
     * @return array{code:string,attribution_id:string,set_at:string,v:int}|null
     */
    public function validatePayload(array $payload): ?array
    {
        $code = isset($payload['code']) && is_string($payload['code'])
            ? strtoupper(trim($payload['code']))
            : '';
        $attributionId = isset($payload['attribution_id']) && is_string($payload['attribution_id'])
            ? trim($payload['attribution_id'])
            : '';
        $setAtRaw = isset($payload['set_at']) && is_string($payload['set_at'])
            ? $payload['set_at']
            : null;

        if ($code === '' || $attributionId === '') {
            return null;
        }

        $days = (int) config('referrals.cookie.days', 30);
        if ($setAtRaw) {
            try {
                $setAt = Carbon::parse($setAtRaw);
            } catch (\Throwable) {
                return null;
            }
            if ($setAt->copy()->addDays($days)->isPast()) {
                return null;
            }
        }

        // Bind code to a real click row — prevents forged JSON substituting
        // another ambassador's referral_code while keeping a stolen attribution_id.
        $click = ReferralClick::query()
            ->where('attribution_id', $attributionId)
            ->first();

        if (! $click) {
            return null;
        }

        if (strtoupper((string) $click->referral_code_snapshot) !== $code) {
            return null;
        }

        return [
            'v' => self::PAYLOAD_VERSION,
            'code' => $code,
            'attribution_id' => $attributionId,
            'set_at' => $setAtRaw ?? $click->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
