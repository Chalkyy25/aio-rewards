<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AmbassadorResource;
use App\Models\AmbassadorProfile;
use App\Models\ReferralConversion;
use App\Models\RewardMilestoneTier;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Milestone-progression admin widget. Counts Rewards Members currently
 * saving toward each active milestone tier, using the same allocation
 * ledger the member side reads (never re-implementing the maths).
 */
class MilestoneProgressionWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $tiers = RewardMilestoneTier::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('threshold')
            ->get();

        // Per-member unallocated (eligible) referral counts.
        //
        //   eligible per profile =
        //     COUNT(referral_conversions WHERE status='approved'
        //           AND id NOT IN (referral_allocations WHERE active_marker IS NOT NULL))
        $eligibleCountsByProfile = ReferralConversion::query()
            ->where('status', 'approved')
            ->whereNotIn('id', function ($sub) {
                $sub->select('referral_conversion_id')
                    ->from('referral_allocations')
                    ->whereNotNull('active_marker');
            })
            ->select('ambassador_profile_id', DB::raw('COUNT(*) as c'))
            ->groupBy('ambassador_profile_id')
            ->pluck('c', 'ambassador_profile_id');

        $totalMembers = AmbassadorProfile::query()->count();
        $activelyProgressing = $eligibleCountsByProfile->filter(fn ($c) => $c > 0)->count();

        $stats = [
            Stat::make('Members progressing', (string) $activelyProgressing)
                ->description(sprintf('of %s total', number_format($totalMembers)))
                ->color($activelyProgressing > 0 ? 'primary' : 'gray')
                ->icon('heroicon-o-user-group')
                ->url(AmbassadorResource::getUrl('index')),
        ];

        // One stat per configured tier: members with ≥ threshold unclaimed.
        foreach ($tiers as $tier) {
            $reached = $eligibleCountsByProfile->filter(fn ($c) => $c >= $tier->threshold)->count();
            $stats[] = Stat::make("Members at {$tier->threshold}+ unclaimed", (string) $reached)
                ->description($tier->title.' available')
                ->color($reached > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-gift');
        }

        return $stats;
    }
}
