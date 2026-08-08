<?php

namespace App\Domain\Payouts;

use App\Enums\PayoutMethod;
use SensitiveParameter;

/**
 * Short-lived plaintext payout destination for an authorised admin reveal.
 *
 * Never persist, log, audit, or put these values into Livewire public state.
 */
final readonly class RevealedPayoutDetails
{
    public function __construct(
        public PayoutMethod $method,
        #[SensitiveParameter] public ?string $accountHolderName,
        #[SensitiveParameter] public ?string $sortCode,
        #[SensitiveParameter] public ?string $accountNumber,
        #[SensitiveParameter] public ?string $paypalEmail = null,
    ) {}

    public function hasBankTransferDetails(): bool
    {
        return $this->method === PayoutMethod::BankTransfer;
    }
}
