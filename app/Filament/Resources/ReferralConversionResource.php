<?php

namespace App\Filament\Resources;

use App\Domain\Referrals\ConversionService;
use App\Filament\Resources\ReferralConversionResource\Pages;
use App\Models\ReferralConversion;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ReferralConversionResource extends Resource
{
    protected static ?string $model = ReferralConversion::class;

    protected static ?string $slug = 'referral-conversions';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Conversions';

    protected static ?string $recordTitleAttribute = 'referral_code_snapshot';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conversion')->schema([
                TextEntry::make('referral_code_snapshot')->badge(),
                TextEntry::make('status')->badge()->color(fn ($state) => match ($state) {
                    'approved' => 'success', 'pending' => 'info', 'reversed' => 'danger', default => 'gray',
                }),
                TextEntry::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (ReferralConversion $r) => '£'.number_format($r->amount_minor / 100, 2)),
                TextEntry::make('pending_until')->dateTime()->placeholder('—'),
                TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                TextEntry::make('reversed_at')->dateTime()->placeholder('—'),
                TextEntry::make('reversed_reason')->placeholder('—'),
            ])->columns(3),

            Section::make('Ambassador')->schema([
                TextEntry::make('ambassadorProfile.user.name')->label('Name'),
                TextEntry::make('ambassadorProfile.user.email')->label('Email')->copyable(),
                TextEntry::make('ambassadorProfile.referral_code')->label('Code')->badge(),
            ])->columns(3),

            Section::make('Purchase')->schema([
                TextEntry::make('purchase.id')->label('Order ID')->copyable(),
                TextEntry::make('purchase.buyer_email')->label('Buyer')->copyable(),
                TextEntry::make('purchase.package.name')->label('Package'),
                TextEntry::make('purchase.status')->label('Payment')->badge(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('referral_code_snapshot')->label('Code')->badge()->searchable(),
                TextColumn::make('ambassadorProfile.user.email')->label('Ambassador')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (ReferralConversion $r) => '£'.number_format($r->amount_minor / 100, 2))
                    ->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'approved' => 'success', 'pending' => 'info', 'reversed' => 'danger', default => 'gray',
                }),
                TextColumn::make('pending_until')->label('Ripe on')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('approved_at')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('reversed_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purchase.id')->label('Order')
                    ->formatStateUsing(fn (ReferralConversion $r) => $r->purchase?->orderReference())
                    ->searchable(query: fn ($q, $s) => $q->where('purchase_id', 'like', '%'.strtolower($s).'%')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'reversed' => 'Reversed',
                ]),
                Filter::make('eligible_for_approval')
                    ->label('Eligible for auto-approval')
                    ->toggle()
                    ->query(fn ($query) => tap($query, function ($q) {
                        $ids = app(ConversionService::class)
                            ->eligibleForApprovalQuery()
                            ->pluck('id');
                        $q->whereIn('id', $ids);
                    })),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (ReferralConversion $r) => $r->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (ReferralConversion $r, ConversionService $svc): void {
                        if ($svc->approve($r, Auth::user())) {
                            Notification::make()->title('Conversion approved')->success()->send();
                        }
                    }),
                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReferralConversion $r) => $r->status !== 'reversed')
                    ->schema([
                        Select::make('reason')->options([
                            'refund' => 'Refund',
                            'chargeback' => 'Chargeback',
                            'admin' => 'Admin decision',
                        ])->required(),
                        Textarea::make('note')->maxLength(500),
                    ])
                    ->action(function (ReferralConversion $r, array $data, ConversionService $svc): void {
                        $svc->reverse($r, $data['reason'], Auth::user());
                        Notification::make()->title('Conversion reversed')->success()->send();
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
            'index' => Pages\ListReferralConversions::route('/'),
            'view' => Pages\ViewReferralConversion::route('/{record}'),
        ];
    }
}
