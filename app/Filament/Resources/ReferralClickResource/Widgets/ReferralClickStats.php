<?php

namespace App\Filament\Resources\ReferralClickResource\Widgets;

use App\Models\ReferralClick;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReferralClickStats extends BaseWidget
{
    protected function getStats(): array
    {
        $today = ReferralClick::whereDate('created_at', today())->where('is_bot', false)->count();
        $last7 = ReferralClick::where('created_at', '>=', now()->subDays(7))->where('is_bot', false)->count();
        $total = ReferralClick::where('is_bot', false)->count();
        $bots = ReferralClick::where('is_bot', true)->count();

        return [
            Stat::make('Valid clicks today', $today),
            Stat::make('Valid clicks last 7 days', $last7),
            Stat::make('Total valid clicks', $total),
            Stat::make('Bot clicks (filtered)', $bots)->color('warning'),
        ];
    }
}
