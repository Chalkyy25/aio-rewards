<?php

namespace App\Domain\Rewards\Events;

use App\Domain\Rewards\MilestoneUnlockSnapshot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when a member's progression first makes a claimable milestone
 * newly available within the current cycle. Payload is intentionally
 * limited to safe identifiers and tier/progress snapshot values.
 */
class RewardMilestoneUnlocked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MilestoneUnlockSnapshot $snapshot,
    ) {}
}
