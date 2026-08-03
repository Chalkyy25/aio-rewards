<?php

namespace App\Domain\Provider\DTOs;

use App\Domain\Provider\Enums\VerificationFailureReason;

/**
 * Minimum-necessary result of a provider verification call.
 * Deliberately contains no subscription metadata beyond eligibility.
 */
final class VerifyCustomerResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly ?VerificationFailureReason $reason = null,
        public readonly ?string $providerCustomerRef = null,
    ) {}

    public static function eligible(?string $providerCustomerRef = null): self
    {
        return new self(true, null, $providerCustomerRef);
    }

    public static function reject(VerificationFailureReason $reason): self
    {
        return new self(false, $reason, null);
    }
}
