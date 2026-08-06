<?php

namespace App\Filament\Widgets;

use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $totalAmbassadors = AmbassadorProfile::count();
        $activePackages = Package::where('is_active', true)->count();
        $paidOrders = Purchase::where('status', 'paid')->count();
        $pendingFulfilment = Purchase::whereIn('fulfilment_status', ['payment_received', 'pending_setup', 'in_progress', 'awaiting_customer'])->count();
        $pendingConversions = ReferralConversion::where('status', 'pending')->count();

        return [
            Stat::make('Ambassadors', (string) $totalAmbassadors)
                ->description('Active + flagged combined')
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Paid orders', (string) $paidOrders)
                ->description($pendingFulfilment.' awaiting fulfilment')
                ->icon('heroicon-o-inbox-stack')
                ->color($pendingFulfilment > 0 ? 'warning' : 'success'),

            Stat::make('Conversions pending', (string) $pendingConversions)
                ->description('Awaiting approval window')
                ->icon('heroicon-o-arrow-trending-up')
                ->color($pendingConversions > 0 ? 'info' : 'gray'),

            Stat::make('Active packages', (string) $activePackages)
                ->description('Live on /packages')
                ->icon('heroicon-o-cube')
                ->color('success'),
        ];
    }
}
