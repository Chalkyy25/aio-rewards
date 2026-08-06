<?php

namespace App\Filament\Resources\OperationsItemResource\Pages;

use App\Domain\Operations\OperationsWriter;
use App\Filament\Resources\OperationsItemResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationsItem extends ViewRecord
{
    protected static string $resource = OperationsItemResource::class;

    protected string $view = 'filament.resources.operations-item.view';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        // First view: record it. This is the mechanism that lets the
        // "paid order not viewed by admin" detector clear itself.
        app(OperationsWriter::class)->markSeen($this->record);
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to queue')
                ->url(OperationsItemResource::getUrl('index'))
                ->color('gray')
                ->icon('heroicon-o-arrow-left'),
        ];
    }
}
