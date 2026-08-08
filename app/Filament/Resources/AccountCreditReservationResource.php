<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountCreditReservationResource\Pages;
use App\Models\AccountCreditReservation;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only reservation inspection. */
class AccountCreditReservationResource extends Resource
{
    protected static ?string $model = AccountCreditReservation::class;

    protected static ?string $slug = 'account-credit-reservations';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationLabel = 'AC Reservations';

    protected static ?int $navigationSort = 43;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reservation')->schema([
                TextEntry::make('status')->badge(),
                TextEntry::make('amount_minor')
                    ->formatStateUsing(fn (AccountCreditReservation $r) => '£'.number_format($r->amount_minor / 100, 2)),
                TextEntry::make('currency'),
                TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                TextEntry::make('committed_at')->dateTime()->placeholder('—'),
                TextEntry::make('released_at')->dateTime()->placeholder('—'),
                TextEntry::make('idempotency_key')->copyable(),
            ])->columns(3),
            Section::make('Links')->schema([
                TextEntry::make('ambassadorProfile.user.email')->label('Member'),
                TextEntry::make('purchase_id')->label('Purchase ULID')->copyable(),
                TextEntry::make('purchase.status')->label('Purchase status'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('ambassadorProfile.user.email')->label('Member')->searchable(),
                TextColumn::make('amount_minor')
                    ->formatStateUsing(fn (AccountCreditReservation $r) => '£'.number_format($r->amount_minor / 100, 2)),
                TextColumn::make('status')->badge(),
                TextColumn::make('purchase_id')->label('Purchase')->limit(12),
                TextColumn::make('expires_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'committed' => 'Committed',
                    'released' => 'Released',
                    'expired' => 'Expired',
                ]),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountCreditReservations::route('/'),
            'view' => Pages\ViewAccountCreditReservation::route('/{record}'),
        ];
    }
}
