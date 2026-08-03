<?php

namespace App\Domain\Provider\Contracts;

use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;

/**
 * Ambassador activation eligibility check.
 *
 * The contract is intentionally narrow so it stays stable if the provider
 * later supports verification via registered email, one-time token, or SSO.
 * New verification shapes should be added as separate methods on this
 * interface (or a sibling contract) — never by bloating verifyActiveCustomer.
 */
interface CustomerVerificationContract
{
    public function verifyActiveCustomer(VerifyCustomerRequest $request): VerifyCustomerResult;

    /**
     * Non-sensitive identifier used in health checks / audit logs.
     */
    public function driverKey(): string;
}
