<?php

namespace App\Filament\Widgets;

use App\Models\Reward;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class RewardsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $pendingApproval = Reward::where('status', 'pending_approval')->count();
        $awaitingPayment = Reward::where('status', 'approved')->count();
        $paidTotalMinor = (int) Reward::where('status', 'paid')->sum('amount_minor');

        $topAmbassador = DB::table('rewards')
            ->join('ambassador_profiles', 'ambassador_profiles.id', '=', 'rewards.ambassador_profile_id')
            ->join('users', 'users.id', '=', 'ambassador_profiles.user_id')
            ->whereIn('rewards.status', ['approved', 'paid'])
            ->groupBy('users.email')
            ->select('users.email', DB::raw('SUM(rewards.amount_minor) as total'))
            ->orderByDesc('total')
            ->limit(1)
            ->first();

        return [
            Stat::make('Rewards awaiting approval', (string) $pendingApproval)
                ->description('Pending approval')
                ->color($pendingApproval > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock'),

            Stat::make('Rewards awaiting payment', (string) $awaitingPayment)
                ->description('Approved, needs payout')
                ->color($awaitingPayment > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Total rewards paid', '£'.number_format($paidTotalMinor / 100, 2))
                ->description('Lifetime payouts')
                ->color('success')
                ->icon('heroicon-o-check-badge'),

            Stat::make('Top ambassador',
                $topAmbassador ? '£'.number_format(((int) $topAmbassador->total) / 100, 2) : '—')
                ->description($topAmbassador->email ?? 'No approvals yet')
                ->color('primary')
                ->icon('heroicon-o-trophy'),
        ];
    }
}
