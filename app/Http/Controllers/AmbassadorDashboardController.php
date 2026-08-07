<?php

namespace App\Http\Controllers;

use App\Domain\Rewards\MilestoneProgressionService;
use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmbassadorDashboardController extends Controller
{
    public function __construct(private readonly MilestoneProgressionService $milestones)
    {
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $profile = $user?->ambassadorProfile;

        $stats = [
            'approved_conversions' => 0,
            'pending_reward_minor' => 0,
            'approved_reward_minor' => 0,
            'paid_reward_minor' => 0,
            'lifetime_earned_minor' => 0,
            'available_reward_minor' => 0,
            'progress_current' => 0,
            'progress_target' => null,
            'progress_remaining' => null,
            'progress_next_amount_minor' => null,
            'progress_bonus_amount_minor' => 0,
            'has_available_now' => false,
        ];

        if ($profile) {
            $rewards = Reward::query()
                ->where('ambassador_profile_id', $profile->id)
                ->selectRaw('status, SUM(amount_minor) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $stats['pending_reward_minor'] = (int) ($rewards['pending_approval'] ?? 0);
            $stats['approved_reward_minor'] = (int) ($rewards['approved'] ?? 0);
            $stats['paid_reward_minor'] = (int) ($rewards['paid'] ?? 0);
            $stats['lifetime_earned_minor'] =
                $stats['approved_reward_minor'] + $stats['paid_reward_minor'];

            $progress = $this->milestones->progressFor($profile);
            $stats['approved_conversions'] = $progress->eligibleCount;
            $stats['available_reward_minor'] = $progress->availableAmountMinor;
            $stats['has_available_now'] = $progress->hasClaim();

            if ($progress->nextTier) {
                $stats['progress_current'] = $progress->eligibleCount;
                $stats['progress_target'] = $progress->nextTier->threshold;
                $stats['progress_remaining'] = $progress->referralsRemaining;
                $stats['progress_next_amount_minor'] = $progress->nextTier->total_reward_amount_minor;
                $stats['progress_bonus_amount_minor'] = $progress->nextTier->bonus_amount_minor;
            } elseif ($progress->availableTier) {
                // At (or past) the top tier — no next milestone.
                $stats['progress_current'] = $progress->availableTier->threshold;
                $stats['progress_target'] = $progress->availableTier->threshold;
                $stats['progress_remaining'] = 0;
                $stats['progress_next_amount_minor'] = $progress->availableTier->total_reward_amount_minor;
            }
        }

        return view('ambassador.dashboard', [
            'stats' => $stats,
        ]);
    }
}
