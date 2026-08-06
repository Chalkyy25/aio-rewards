<?php

namespace App\Domain\Provider\Drivers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;
use App\Domain\Provider\Exceptions\ProviderUnavailableException;

/**
 * Wrapper driver used when Provider Verification is toggled OFF in Settings.
 *
 * This driver deliberately REFUSES to complete any activation — it throws
 * ProviderUnavailableException so the activation page surfaces the standard
 * temporarily-unavailable message. Turning verification off is a
 * maintenance signal, NOT a silent bypass that lets unverified accounts
 * activate. The diagnostic key remains distinct so this state is visible
 * in audits and health widgets.
 */
class DisabledVerificationDriver implements CustomerVerificationContract
{
    public function driverKey(): string
    {
        return 'disabled';
    }

    public function verifyActiveCustomer(VerifyCustomerRequest $request): VerifyCustomerResult
    {
        throw new ProviderUnavailableException('Provider verification is disabled.');
    }
}
