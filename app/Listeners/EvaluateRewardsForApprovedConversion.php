<?php

namespace App\Listeners;

use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Domain\Rewards\RewardsEngine;

class EvaluateRewardsForApprovedConversion
{
    public function __construct(private readonly RewardsEngine $engine) {}

    public function handle(ReferralConversionApproved $event): void
    {
        $this->engine->onConversionApproved($event->conversion);
    }
}
