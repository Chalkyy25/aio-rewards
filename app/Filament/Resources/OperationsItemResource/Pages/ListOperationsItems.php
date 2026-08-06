<?php

namespace App\Filament\Resources\OperationsItemResource\Pages;

use App\Filament\Resources\OperationsItemResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOperationsItems extends ListRecords
{
    protected static string $resource = OperationsItemResource::class;

    public function getTitle(): string
    {
        return 'Operations Centre';
    }

    public function getSubheading(): ?string
    {
        return 'Auto-generated work queue for the AIO Rewards administration team.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\OperationsBellWidget::class,
        ];
    }

    /** Prioritise critical/high items above the default created_at sort. */
    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()
            ?->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderByDesc('created_at');
    }
}
