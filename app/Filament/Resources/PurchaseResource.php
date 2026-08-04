<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Purchase;
use App\Support\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $slug = 'purchases';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Purchases';

    protected static ?string $recordTitleAttribute = 'buyer_email';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->schema([
                TextEntry::make('id')->label('Order ID')->copyable(),
                TextEntry::make('package.name')->label('Package'),
                TextEntry::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Purchase $r) => $r->priceFormatted()),
                TextEntry::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'refunded' => 'warning',
                        'chargeback' => 'danger',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('fulfilment_status')->badge()
                    ->color(fn ($state) => $state === 'fulfilled' ? 'success' : 'gray'),
                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                TextEntry::make('fulfilled_at')->dateTime()->placeholder('—'),
            ])->columns(2),

            Section::make('Buyer')->schema([
                TextEntry::make('buyer_name'),
                TextEntry::make('buyer_email')->copyable(),
                TextEntry::make('preferred_username')->copyable(),
                TextEntry::make('delivery_method')->badge(),
                TextEntry::make('buyer_phone')->placeholder('—'),
                TextEntry::make('buyer_telegram')->placeholder('—'),
            ])->columns(2),

            Section::make('Attribution')->schema([
                TextEntry::make('referral_code_snapshot')->badge()->placeholder('—'),
                TextEntry::make('ambassadorSnapshot.user.name')->label('Ambassador')->placeholder('—'),
                TextEntry::make('attribution_id')->label('Attribution ID')->copyable()->placeholder('—'),
            ])->columns(3),

            Section::make('Stripe')->schema([
                TextEntry::make('stripe_session_id')->label('Session')->copyable()->placeholder('—'),
                TextEntry::make('stripe_payment_intent_id')->label('Payment Intent')->copyable()->placeholder('—'),
                TextEntry::make('stripe_charge_id')->label('Charge')->copyable()->placeholder('—'),
            ])->columns(1),

            Section::make('Compliance')->schema([
                TextEntry::make('terms_accepted_at')->dateTime()->placeholder('—'),
                TextEntry::make('privacy_accepted_at')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('id')->label('Order')
                    ->formatStateUsing(fn (Purchase $r) => $r->orderReference())
                    ->searchable(query: fn ($q, $s) => $q->where('id', 'like', "%".strtolower($s)."%")),
                TextColumn::make('package.name')->label('Package')->searchable(),
                TextColumn::make('buyer_email')->searchable(),
                TextColumn::make('preferred_username')->label('Username')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Purchase $r) => $r->priceFormatted())->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'refunded' => 'warning',
                        'chargeback' => 'danger',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('fulfilment_status')->label('Fulfilment')->badge()
                    ->color(fn ($state) => $state === 'fulfilled' ? 'success' : 'gray'),
                TextColumn::make('referral_code_snapshot')->label('Ref')->badge()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                    'refunded' => 'Refunded',
                    'chargeback' => 'Chargeback',
                ]),
                SelectFilter::make('fulfilment_status')->options([
                    'unfulfilled' => 'Unfulfilled',
                    'fulfilled' => 'Fulfilled',
                ]),
                TernaryFilter::make('referral_code_snapshot')
                    ->label('Referred')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('referral_code_snapshot'),
                        false: fn ($q) => $q->whereNull('referral_code_snapshot'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('markFulfilled')
                    ->label('Mark fulfilled')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Purchase $r) => $r->status === 'paid' && $r->fulfilment_status !== 'fulfilled')
                    ->schema([
                        Textarea::make('note')->label('Fulfilment note (optional)')->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Purchase $r, array $data): void {
                        $r->update([
                            'fulfilment_status' => 'fulfilled',
                            'fulfilled_at' => now(),
                            'fulfilled_by_user_id' => Auth::id(),
                        ]);
                        AuditLogger::record(
                            action: 'purchase.fulfilled',
                            subject: $r,
                            after: ['note' => $data['note'] ?? null],
                        );
                        Notification::make()->title('Marked as fulfilled')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'view' => Pages\ViewPurchase::route('/{record}'),
        ];
    }
}
