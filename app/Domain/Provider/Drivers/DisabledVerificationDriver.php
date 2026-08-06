<?php

namespace App\Domain\Provider\Drivers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;

/**
 * Wrapper driver used when Provider Verification is toggled OFF in Settings.
 * Every call returns "eligible" so admins can temporarily bypass verification
 * during maintenance without editing code. Diagnostic key remains distinct so
 * the state is visible in audits and health widgets.
 */
class DisabledVerificationDriver implements CustomerVerificationContract
{
    public function driverKey(): string
    {
        return 'disabled';
    }

    public function verifyActiveCustomer(VerifyCustomerRequest $request): VerifyCustomerResult
    {
        return VerifyCustomerResult::eligible($request->providerUsername);
    }
}
