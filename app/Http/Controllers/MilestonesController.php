<?php

namespace App\Http\Controllers;

use App\Domain\Rewards\MilestoneClaimUnavailableException;
use App\Domain\Rewards\MilestoneProgress;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MilestonesController extends Controller
{
    public function __construct(private readonly MilestoneProgressionService $service) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $profile = $user->ambassadorProfile;
        abort_unless($profile, 403);

        $profile->loadMissing('payoutProfile');
        $progress = $this->service->progressFor($profile);
        $summary = $this->buildSummary($profile->id, $progress);

        $payout = $profile->payoutProfile;
        $claimMethod = ($payout && $payout->isConfigured() && $payout->preferred_method->isConfigurable())
            ? $payout->preferred_method
            : null;

        return view('ambassador.milestones.index', [
            'profile' => $profile,
            'progress' => $progress,
            'summary' => $summary,
            'claimMethod' => $claimMethod,
            'canClaim' => $claimMethod !== null,
        ]);
    }

    public function claim(Request $request, RewardMilestoneTier $tier): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->ambassadorProfile;
        abort_unless($profile, 403);

        $idempotency = (string) $request->input('idempotency_key', '');
        $idempotency = $idempotency !== '' ? substr($idempotency, 0, 128) : null;

        try {
            $reward = $this->service->claim($profile, $tier, $user, $idempotency);
        } catch (MilestoneClaimUnavailableException $e) {
            return redirect()
                ->route('ambassador.milestones')
                ->with('milestone_error', $e->getMessage());
        }

        return redirect()
            ->route('ambassador.milestones')
            ->with('milestone_claimed', [
                'amount' => $reward->memberFacingAmountFormatted(),
                'method' => $reward->claimedPayoutMethod()?->value,
                'reward_id' => $reward->id,
            ]);
    }

    /** @return array<string, int> */
    private function buildSummary(int $profileId, MilestoneProgress $progress): array
    {
        $rewards = Reward::query()
            ->where('ambassador_profile_id', $profileId)
            ->get(['status', 'amount_minor', 'account_credit_bonus_minor_snapshot', 'preferred_payout_method_snapshot']);

        $pending = 0;
        $approved = 0;
        $paid = 0;
        foreach ($rewards as $reward) {
            $display = $reward->memberFacingAmountMinor();
            match ($reward->status) {
                'pending_approval' => $pending += $display,
                'approved' => $approved += $display,
                'paid' => $paid += $display,
                default => null,
            };
        }

        return [
            'lifetime_minor' => $approved + $paid,
            'paid_minor' => $paid,
            'pending_minor' => $pending,
            'approved_pending_payment_minor' => $approved,
            'available_now_minor' => $progress->availableAmountMinor,
            'approved_referrals' => ReferralConversion::query()
                ->where('ambassador_profile_id', $profileId)
                ->where('status', 'approved')
                ->count(),
        ];
    }
}
