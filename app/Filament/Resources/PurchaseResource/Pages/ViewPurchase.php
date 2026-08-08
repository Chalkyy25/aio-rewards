<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Filament\Resources\PurchaseResource;
use App\Models\Purchase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('completeOrder')
                ->label('Complete Order')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(function (): bool {
                    /** @var Purchase $record */
                    $record = $this->getRecord();

                    return app(OrderFulfilmentService::class)->isEligibleForCompleteAction($record);
                })
                ->requiresConfirmation()
                ->modalHeading('Complete order?')
                ->modalDescription('This will mark the order as Ready to use, make the provisioned account details available to the customer, and send the order completion notification.')
                ->modalSubmitActionLabel('Complete Order')
                ->action(function (OrderFulfilmentService $svc): void {
                    /** @var Purchase $record */
                    $record = $this->getRecord();

                    try {
                        $svc->transition($record, OrderStatus::Completed, Auth::user());
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title('Cannot complete order')
                            ->body($e->getMessage())
                            ->danger()
                            ->actions([
                                Action::make('editFulfilment')
                                    ->label('Edit fulfilment details')
                                    ->url(PurchaseResource::getUrl('edit', ['record' => $record])),
                            ])
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Order completed')
                        ->body('The customer can now see their provisioned account details on the order status page.')
                        ->success()
                        ->send();

                    $this->getRecord()->refresh();
                    $this->refreshFormData([
                        'fulfilment_status',
                        'completed_at',
                        'fulfilled_at',
                        'fulfilled_by_user_id',
                        'completed_email_sent_at',
                    ]);
                }),
            EditAction::make()
                ->label('Fulfilment details'),
        ];
    }
}
