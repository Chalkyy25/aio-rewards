<?php

namespace App\Domain\Payouts;

/**
 * Request-scoped holder for temporarily decrypted payout details.
 *
 * Bound as a singleton so a successful reveal can hand details to the
 * Filament modal in the same request without putting plaintext into
 * Livewire component state / dehydration snapshots.
 */
final class RevealedPayoutDetailsStore
{
    private ?RevealedPayoutDetails $details = null;

    public function put(RevealedPayoutDetails $details): void
    {
        $this->details = $details;
    }

    public function peek(): ?RevealedPayoutDetails
    {
        return $this->details;
    }

    public function clear(): void
    {
        $this->details = null;
    }
}
