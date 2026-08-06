<?php

namespace App\Http\Controllers;

use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardRule;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmbassadorDashboardController extends Controller
{
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
            'next_rule' => null,
            'progress_current' => 0,
            'progress_target' => null,
            'progress_remaining' => null,
        ];

        if ($profile) {
            $stats['approved_conversions'] = ReferralConversion::query()
                ->where('ambassador_profile_id', $profile->id)
                ->where('status', 'approved')
                ->count();

            $rewards = Reward::query()
                ->where('ambassador_profile_id', $profile->id)
                ->selectRaw('status, SUM(amount_minor) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $stats['pending_reward_minor'] = (int) ($rewards['pending_approval'] ?? 0);
            $stats['approved_reward_minor'] = (int) ($rewards['approved'] ?? 0);
            $stats['paid_reward_minor'] = (int) ($rewards['paid'] ?? 0);
            // Lifetime earned = anything that landed in the ambassador's tally
            // (approved + paid). Rejected + reversed are excluded.
            $stats['lifetime_earned_minor'] =
                $stats['approved_reward_minor'] + $stats['paid_reward_minor'];

            // Progress against the highest-priority active every_n_cash rule.
            $rule = RewardRule::query()
                ->where('is_active', true)
                ->where('kind', 'every_n_cash')
                ->where('trigger_count', '>', 0)
                ->orderBy('sort_order')
                ->first();

            if ($rule) {
                $target = (int) $rule->trigger_count;
                $current = $stats['approved_conversions'] % $target;
                $stats['next_rule'] = $rule;
                $stats['progress_current'] = $current;
                $stats['progress_target'] = $target;
                $stats['progress_remaining'] = max(0, $target - $current);
            }
        }

        return view('ambassador.dashboard', [
            'stats' => $stats,
        ]);
    }
}
