<?php

namespace App\Domain\Referrals\DTOs;

/**
 * Parsed context of a referral-link click.
 */
final class AttributionContext
{
    public function __construct(
        public readonly string $referralCode,
        public readonly string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $refererUrl = null,
        public readonly ?string $utmSource = null,
        public readonly ?string $utmMedium = null,
        public readonly ?string $utmCampaign = null,
    ) {}
}
