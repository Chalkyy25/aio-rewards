<?php

namespace App\Domain\Payouts;

use App\Domain\Rewards\Events\RewardApproved;
use App\Models\MemberPayoutProfile;
use App\Notifications\MissingPayoutMethodNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Surfaces a one-shot prompt when an approved reward is ready for payment
 * but the Rewards Member has no payout preference configured.
 *
 * Idempotency: ambassador_profiles.payout_prompt_sent_at is claimed with an
 * atomic UPDATE so concurrent approvals cannot double-send.
 */
final class NotifyMissingPayoutMethod implements ShouldQueue
{
    public function handle(RewardApproved $event): void
    {
        $reward = $event->reward->fresh(['ambassadorProfile.user']);
        $profile = $reward?->ambassadorProfile;
        $user = $profile?->user;

        if (! $reward || ! $profile || ! $user) {
            return;
        }

        if ($reward->status !== 'approved') {
            return;
        }

        $configured = MemberPayoutProfile::query()
            ->where('ambassador_profile_id', $profile->id)
            ->get()
            ->first(fn (MemberPayoutProfile $p) => $p->isConfigured());

        if ($configured) {
            return;
        }

        $claimed = DB::table('ambassador_profiles')
            ->where('id', $profile->id)
            ->whereNull('payout_prompt_sent_at')
            ->update(['payout_prompt_sent_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $user->notify(new MissingPayoutMethodNotification($reward));
    }
}
