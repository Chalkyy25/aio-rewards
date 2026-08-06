<?php

namespace App\Domain\Rewards\Events;

use App\Models\Reward;
use Illuminate\Foundation\Events\Dispatchable;

class RewardApproved
{
    use Dispatchable;

    public function __construct(public readonly Reward $reward) {}
}
