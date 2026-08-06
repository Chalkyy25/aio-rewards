<?php

namespace App\Filament\Resources;

use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Purchase;
use App\Support\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    protected static ?string $slug = 'orders';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Orders';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Orders';

    protected static ?string $recordTitleAttribute = 'buyer_email';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provisioned credentials')
                ->description('Stored encrypted at rest. Shown to the buyer once the order is marked Completed.')
                ->schema([
                    TextInput::make('provisioned_username_enc')
                        ->label('AIO Media username')
                        ->maxLength(190)
                        ->helperText('The final username on the upstream provider panel.'),
                    TextInput::make('provisioned_password_enc')
                        ->label('AIO Media password')
                        ->password()->revealable()
                        ->maxLength(190)
                        ->helperText('Leave blank to keep the current stored password.')
                        ->dehydrated(fn ($state) => filled($state)),
                    DatePicker::make('provisioned_expires_on')
                        ->label('Expires on'),
                ])->columns(3),

            Section::make('Setup for the customer')
                ->schema([
                    Textarea::make('setup_instructions_md')
                        ->label('Setup instructions')
                        ->helperText('Plain text or Markdown. Shown on the customer status page once Completed.')
                        ->rows(6)
                        ->maxLength(4000)
                        ->columnSpanFull(),
                    Repeater::make('download_links')
                        ->label('Download links')
                        ->schema([
                            TextInput::make('label')->required()->maxLength(120),
                            TextInput::make('url')->url()->required()->maxLength(500),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    Textarea::make('fulfilment_notes')
                        ->label('Internal fulfilment notes')
                        ->helperText('Not visible to the customer.')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ]);
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
                TextEntry::make('fulfilment_status')->label('Fulfilment')->badge()
                    ->color(fn (Purchase $r) => $r->statusColor())
                    ->formatStateUsing(fn (Purchase $r) => $r->statusLabel()),
                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
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
                TextEntry::make('referralConversion.status')->label('Conversion')->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'pending' => 'info',
                        'reversed' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
            ])->columns(3),

            Section::make('Provisioned credentials')
                ->description('Stored encrypted. Buyer sees these on the status page when Completed.')
                ->schema([
                    TextEntry::make('provisioned_username_enc')->label('AIO username')->placeholder('—')->copyable(),
                    TextEntry::make('provisioned_password_enc')->label('AIO password')
                        ->formatStateUsing(fn ($state) => $state ? '••••••••' : '—'),
                    TextEntry::make('provisioned_expires_on')->label('Expires on')->date()->placeholder('—'),
                ])->columns(3),

            Section::make('Customer status URL')->schema([
                TextEntry::make('customer_view_token')
                    ->label('Public link')
                    ->formatStateUsing(fn (Purchase $r) => $r->customer_view_token ? url('/order/'.$r->customer_view_token) : '—')
                    ->copyable()
                    ->placeholder('—'),
            ]),

            Section::make('Stripe')->schema([
                TextEntry::make('stripe_session_id')->label('Session')->copyable()->placeholder('—'),
                TextEntry::make('stripe_payment_intent_id')->label('Payment Intent')->copyable()->placeholder('—'),
                TextEntry::make('stripe_charge_id')->label('Charge')->copyable()->placeholder('—'),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('id')->label('Order')
                    ->formatStateUsing(fn (Purchase $r) => $r->orderReference())
                    ->searchable(query: fn ($q, $s) => $q->where('id', 'like', '%'.strtolower($s).'%')),
                TextColumn::make('package.name')->label('Package')->searchable(),
                TextColumn::make('buyer_email')->searchable(),
                TextColumn::make('preferred_username')->label('Username')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Purchase $r) => $r->priceFormatted())->sortable(),
                TextColumn::make('status')->label('Payment')->badge()
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success',
                        'refunded' => 'warning',
                        'chargeback' => 'danger',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('fulfilment_status')->label('Fulfilment')->badge()
                    ->color(fn (Purchase $r) => $r->statusColor())
                    ->formatStateUsing(fn (Purchase $r) => $r->statusLabel()),
                TextColumn::make('referral_code_snapshot')->label('Ref')->badge()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Payment status')->options([
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                    'failed' => 'Failed',
                    'refunded' => 'Refunded',
                    'chargeback' => 'Chargeback',
                ]),
                SelectFilter::make('fulfilment_status')->label('Fulfilment')
                    ->options(OrderStatus::options()),
                TernaryFilter::make('referral_code_snapshot')
                    ->label('Referred')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('referral_code_snapshot'),
                        false: fn ($q) => $q->whereNull('referral_code_snapshot'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->label('Fulfilment details'),
                Action::make('transition')
                    ->label('Change status')
                    ->icon('heroicon-o-arrow-path')
                    ->schema([
                        Select::make('to')
                            ->label('Move to')
                            ->options(OrderStatus::options())
                            ->required(),
                        Textarea::make('note')->label('Note (optional)')->maxLength(500),
                    ])
                    ->action(function (Purchase $r, array $data, OrderFulfilmentService $svc): void {
                        try {
                            $svc->transition($r, OrderStatus::from($data['to']), Auth::user());
                            if (! empty($data['note'])) {
                                AuditLogger::record(
                                    action: 'order.transition_note',
                                    subject: $r,
                                    after: ['note' => $data['note']],
                                );
                            }
                            Notification::make()->title('Status updated')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title('Illegal transition')->body($e->getMessage())->danger()->send();
                        }
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
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }
}
