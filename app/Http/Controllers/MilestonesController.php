<?php

namespace App\Http\Controllers;

use App\Domain\Rewards\MilestoneClaimUnavailableException;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MilestonesController extends Controller
{
    public function __construct(private readonly MilestoneProgressionService $service)
    {
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $profile = $user->ambassadorProfile;
        abort_unless($profile, 403);

        $progress = $this->service->progressFor($profile);
        $summary = $this->buildSummary($profile->id, $progress);

        return view('ambassador.milestones.index', [
            'profile' => $profile,
            'progress' => $progress,
            'summary' => $summary,
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
                'amount' => $reward->amountFormatted(),
                'reward_id' => $reward->id,
            ]);
    }

    /** @return array<string, int> */
    private function buildSummary(int $profileId, \App\Domain\Rewards\MilestoneProgress $progress): array
    {
        $totals = Reward::query()
            ->where('ambassador_profile_id', $profileId)
            ->selectRaw('status, SUM(amount_minor) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($totals['pending_approval'] ?? 0);
        $approved = (int) ($totals['approved'] ?? 0);
        $paid = (int) ($totals['paid'] ?? 0);

        return [
            'lifetime_minor' => $approved + $paid,
            'paid_minor' => $paid,
            'pending_minor' => $pending,
            'approved_pending_payment_minor' => $approved,
            'available_now_minor' => $progress->availableAmountMinor,
            'approved_referrals' => \App\Models\ReferralConversion::query()
                ->where('ambassador_profile_id', $profileId)
                ->where('status', 'approved')
                ->count(),
        ];
    }
}
