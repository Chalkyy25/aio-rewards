<?php

namespace App\Domain\Rewards\Events;

use App\Models\Reward;
use Illuminate\Foundation\Events\Dispatchable;

class RewardCreated
{
    use Dispatchable;

    public function __construct(public readonly Reward $reward) {}
}
