<?php

namespace App\Filament\Widgets;

use App\Enums\OperationsStatus;
use App\Enums\OperationsType;
use App\Filament\Resources\OperationsItemResource;
use App\Filament\Resources\ReferralAllocationResource;
use App\Filament\Resources\RewardResource;
use App\Models\OperationsItem;
use App\Models\ReferralAllocation;
use App\Models\Reward;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Reward-milestone-aware admin overview:
 *  - Claims awaiting approval, awaiting payment
 *  - Overdue ops alerts (stale claims / unpaid approved)
 *  - Paid this month + lifetime
 *  - Allocated (active) vs released allocations
 */
class RewardsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $pendingApproval = Reward::where('status', 'pending_approval')->count();
        $awaitingPayment = Reward::where('status', 'approved')->count();
        $paidTotalMinor = (int) Reward::where('status', 'paid')->sum('amount_minor');
        $paidThisMonthMinor = (int) Reward::where('status', 'paid')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount_minor');
        $activeAllocations = ReferralAllocation::query()->whereNotNull('active_marker')->count();
        $releasedAllocations = ReferralAllocation::query()->whereNull('active_marker')->count();

        $claimsOverdue = OperationsItem::query()
            ->whereIn('status', OperationsStatus::openValues())
            ->where('type', OperationsType::RewardAwaitingApproval->value)
            ->count();
        $unpaidOverdue = OperationsItem::query()
            ->whereIn('status', OperationsStatus::openValues())
            ->where('type', OperationsType::RewardApprovedAwaitingPayment->value)
            ->count();

        return [
            Stat::make('Claims awaiting approval', (string) $pendingApproval)
                ->description('Pending approval')
                ->color($pendingApproval > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-clock')
                ->url(RewardResource::getUrl('index', ['tableFilters[status][value]' => 'pending_approval'])),

            Stat::make('Claims overdue for approval', (string) $claimsOverdue)
                ->description('Past ops threshold')
                ->color($claimsOverdue > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-exclamation-triangle')
                ->url(OperationsItemResource::getUrl('index', [
                    'tableFilters[type][values][0]' => OperationsType::RewardAwaitingApproval->value,
                    'tableFilters[open][value]' => true,
                ])),

            Stat::make('Awaiting payment', (string) $awaitingPayment)
                ->description('Approved, needs payout')
                ->color($awaitingPayment > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-banknotes')
                ->url(RewardResource::getUrl('index', ['tableFilters[status][value]' => 'approved'])),

            Stat::make('Approved rewards overdue for payment', (string) $unpaidOverdue)
                ->description('Past ops threshold')
                ->color($unpaidOverdue > 0 ? 'danger' : 'gray')
                ->icon('heroicon-o-banknotes')
                ->url(OperationsItemResource::getUrl('index', [
                    'tableFilters[type][values][0]' => OperationsType::RewardApprovedAwaitingPayment->value,
                    'tableFilters[open][value]' => true,
                ])),

            Stat::make('Paid this month', '£'.number_format($paidThisMonthMinor / 100, 2))
                ->description('Rolling calendar month')
                ->color('success')
                ->icon('heroicon-o-check-badge'),

            Stat::make('Total rewards paid', '£'.number_format($paidTotalMinor / 100, 2))
                ->description('Lifetime payouts')
                ->color('success')
                ->icon('heroicon-o-trophy'),

            Stat::make('Allocated referrals', (string) $activeAllocations)
                ->description('Currently active in the ledger')
                ->color('primary')
                ->icon('heroicon-o-link')
                ->url(ReferralAllocationResource::getUrl('index', ['tableFilters[state][value]' => 'active'])),

            Stat::make('Released allocations', (string) $releasedAllocations)
                ->description('Freed by admin rejections')
                ->color('gray')
                ->icon('heroicon-o-arrow-uturn-left')
                ->url(ReferralAllocationResource::getUrl('index', ['tableFilters[state][value]' => 'released'])),
        ];
    }
}
