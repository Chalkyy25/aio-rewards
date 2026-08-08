<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountCreditTransactionResource\Pages;
use App\Models\AccountCreditTransaction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only Account Credit ledger inspection. No manual balance editing.
 */
class AccountCreditTransactionResource extends Resource
{
    protected static ?string $model = AccountCreditTransaction::class;

    protected static ?string $slug = 'account-credit-ledger';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Account Credit Ledger';

    protected static ?int $navigationSort = 42;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ledger entry')->schema([
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('direction')->badge(),
                TextEntry::make('source')->badge()
                    ->formatStateUsing(fn (AccountCreditTransaction $r) => $r->sourceLabel()),
                TextEntry::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (AccountCreditTransaction $r) => $r->amountFormatted()),
                TextEntry::make('currency'),
                TextEntry::make('origin'),
                TextEntry::make('idempotency_key')->copyable(),
                TextEntry::make('note')->placeholder('—'),
            ])->columns(3),

            Section::make('Member')->schema([
                TextEntry::make('ambassadorProfile.user.name')->label('Name'),
                TextEntry::make('ambassadorProfile.user.email')->label('Email')->copyable(),
                TextEntry::make('ambassador_profile_id')->label('Profile ID'),
            ])->columns(3),

            Section::make('Related')->schema([
                TextEntry::make('reward_id')->label('Reward')->placeholder('—'),
                TextEntry::make('reward.amount_minor')->label('Reward cash')
                    ->formatStateUsing(fn ($state) => $state !== null ? '£'.number_format(((int) $state) / 100, 2) : '—'),
                TextEntry::make('reward.account_credit_bonus_minor_snapshot')->label('Bonus snapshot')
                    ->formatStateUsing(fn ($state) => $state !== null ? '£'.number_format(((int) $state) / 100, 2) : '—'),
                TextEntry::make('purchase_id')->label('Purchase ULID')->placeholder('—')->copyable(),
                TextEntry::make('purchase.orderReference')->label('Order ref')->placeholder('—'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('ambassadorProfile.user.email')->label('Member')->searchable(),
                TextColumn::make('source')->label('Type')
                    ->formatStateUsing(fn (AccountCreditTransaction $r) => $r->sourceLabel())
                    ->badge(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (AccountCreditTransaction $r) => $r->amountFormatted())
                    ->sortable(),
                TextColumn::make('reward_id')->label('Reward')->placeholder('—')->toggleable(),
                TextColumn::make('purchase_id')->label('Purchase')->limit(12)->placeholder('—')->toggleable(),
                TextColumn::make('origin')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('source')->options([
                    AccountCreditTransaction::SOURCE_REWARD_FULFILMENT => 'Reward Credit',
                    AccountCreditTransaction::SOURCE_REWARD_BONUS => 'Milestone Bonus',
                    AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION => 'Package Purchase',
                    AccountCreditTransaction::SOURCE_CREDIT_RESTORATION => 'Credit Restoration',
                    AccountCreditTransaction::SOURCE_ADMIN_ADJUSTMENT => 'Admin adjustment',
                    AccountCreditTransaction::SOURCE_REVERSAL => 'Reversal',
                ]),
                SelectFilter::make('direction')->options([
                    'credit' => 'Credit',
                    'debit' => 'Debit',
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
            'index' => Pages\ListAccountCreditTransactions::route('/'),
            'view' => Pages\ViewAccountCreditTransaction::route('/{record}'),
        ];
    }
}
