<?php

namespace App\Filament\Widgets;

use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Models\OperationsItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Notification-bell style overview: counts of open work items broken
 * down by priority. Sits on the Operations Centre landing and updates
 * every 30 seconds.
 */
class OperationsBellWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -100;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $base = OperationsItem::query()->whereIn('status', OperationsStatus::openValues());

        $total = (clone $base)->count();
        $critical = (clone $base)->where('priority', OperationsPriority::Critical->value)->count();
        $high = (clone $base)->where('priority', OperationsPriority::High->value)->count();
        $unassigned = (clone $base)->whereNull('assigned_user_id')->count();
        $overdue = (clone $base)->whereNotNull('due_at')->where('due_at', '<', now())->count();

        return [
            Stat::make('Open items', (string) $total)
                ->description('Actionable work in the queue')
                ->icon('heroicon-o-inbox')
                ->color($total > 0 ? ($critical > 0 ? 'danger' : 'warning') : 'success')
                ->extraAttributes(['data-testid' => 'ops-bell-total']),

            Stat::make('Critical', (string) $critical)
                ->description($critical > 0 ? 'Needs attention now' : 'None')
                ->icon('heroicon-o-bell-alert')
                ->color($critical > 0 ? 'danger' : 'gray')
                ->extraAttributes(['data-testid' => 'ops-bell-critical']),

            Stat::make('High', (string) $high)
                ->description('Escalate or resolve today')
                ->icon('heroicon-o-arrow-trending-up')
                ->color($high > 0 ? 'warning' : 'gray')
                ->extraAttributes(['data-testid' => 'ops-bell-high']),

            Stat::make('Overdue', (string) $overdue)
                ->description('Past their due time')
                ->icon('heroicon-o-clock')
                ->color($overdue > 0 ? 'danger' : 'gray')
                ->extraAttributes(['data-testid' => 'ops-bell-overdue']),

            Stat::make('Unassigned', (string) $unassigned)
                ->description('Waiting for an owner')
                ->icon('heroicon-o-user-plus')
                ->color($unassigned > 0 ? 'warning' : 'gray')
                ->extraAttributes(['data-testid' => 'ops-bell-unassigned']),
        ];
    }
}
