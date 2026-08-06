<?php

namespace App\Filament\Widgets;

use App\Models\Purchase;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentOrdersWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent orders';

    public function table(Table $table): Table
    {
        return $table
            ->query(Purchase::query()->latest()->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->since(),
                TextColumn::make('id')->label('Order')
                    ->formatStateUsing(fn (Purchase $r) => $r->orderReference())
                    ->url(fn (Purchase $r) => route('filament.admin.resources.orders.view', $r)),
                TextColumn::make('buyer_email')->label('Buyer')->limit(40),
                TextColumn::make('package.name')->label('Package'),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Purchase $r) => $r->priceFormatted()),
                TextColumn::make('status')->label('Payment')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'refunded' => 'warning',
                        'chargeback' => 'danger', 'failed' => 'danger', default => 'gray',
                    }),
                TextColumn::make('fulfilment_status')->label('Fulfilment')->badge()
                    ->color(fn (Purchase $r) => $r->statusColor())
                    ->formatStateUsing(fn (Purchase $r) => $r->statusLabel()),
                TextColumn::make('referral_code_snapshot')->label('Ref')->badge()->placeholder('—'),
            ])
            ->paginated(false);
    }
}
