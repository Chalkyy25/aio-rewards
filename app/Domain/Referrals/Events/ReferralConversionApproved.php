<?php

namespace App\Domain\Referrals\Events;

use App\Models\ReferralConversion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when a ReferralConversion is moved from `pending` to `approved`,
 * either by the automatic sweeper or by an admin. Downstream listeners
 * (e.g. Rewards Engine in a later phase) subscribe to this event to
 * evaluate milestone rules.
 */
class ReferralConversionApproved
{
    use Dispatchable;

    public function __construct(
        public readonly ReferralConversion $conversion,
        public readonly bool $auto = false,
    ) {}
}
