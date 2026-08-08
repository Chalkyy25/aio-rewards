<?php

namespace App\Listeners;

use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Domain\Rewards\MilestoneUnlockNotifier;
use Illuminate\Support\Facades\Log;

/**
 * After a qualifying conversion is approved, ask the milestone domain
 * whether a claimable tier has newly become available and notify once.
 *
 * Failures are logged and recorded on the idempotency row (failed status)
 * but must not abort the conversion-approval pipeline.
 */
class EvaluateMilestoneUnlockForApprovedConversion
{
    public function __construct(
        private readonly MilestoneUnlockNotifier $notifier,
    ) {}

    public function handle(ReferralConversionApproved $event): void
    {
        $profile = $event->conversion->ambassadorProfile()->with('user')->first();
        if ($profile === null) {
            return;
        }

        try {
            $this->notifier->evaluate($profile);
        } catch (\Throwable $e) {
            Log::error('milestone.unlock_notification.listener_failed', [
                'ambassador_profile_id' => $profile->id,
                'conversion_id' => $event->conversion->id,
                'exception' => $e::class,
            ]);
        }
    }
}
