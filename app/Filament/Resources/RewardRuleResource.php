<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RewardRuleResource\Pages;
use App\Models\RewardRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Historical Reward Rules admin. Legacy every_n_cash auto-mint is disabled
 * for launch — rules can be viewed but cannot be re-activated.
 */
class RewardRuleResource extends Resource
{
    protected static ?string $model = RewardRule::class;

    protected static ?string $slug = 'reward-rules';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Reward Rules (legacy)';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rule')->schema([
                Placeholder::make('legacy_notice')
                    ->label('Launch notice')
                    ->content('Legacy every_n_cash automatic rewards are disabled. Milestone progression + ReferralAllocation is the sole earn path. Historical rules are retained read-mostly; activating every_n_cash is blocked.'),
                TextInput::make('name')->required()->maxLength(190)->disabled(fn (?RewardRule $record) => $record !== null),
                Select::make('kind')->required()->default('every_n_cash')->options([
                    'every_n_cash' => 'Every X approved conversions → fixed cash (DISABLED)',
                    'percentage' => 'Percentage of sale (coming soon)',
                    'lifetime_revenue' => 'Lifetime revenue bonus (coming soon)',
                ])->live()->disabled(fn (?RewardRule $record) => $record !== null),
                TextInput::make('trigger_count')
                    ->label('Every N approved conversions')
                    ->numeric()->minValue(1)->default(5)->required()
                    ->visible(fn ($get) => $get('kind') === 'every_n_cash')
                    ->disabled(fn (?RewardRule $record) => $record !== null),
                TextInput::make('amount_minor')
                    ->label('Reward amount (minor units, pence)')
                    ->numeric()->minValue(0)->required()
                    ->helperText('£50.00 → 5000.')
                    ->visible(fn ($get) => $get('kind') === 'every_n_cash')
                    ->disabled(fn (?RewardRule $record) => $record !== null),
                TextInput::make('currency')->required()->default('gbp')->maxLength(3)
                    ->disabled(fn (?RewardRule $record) => $record !== null),
                TextInput::make('percentage_bps')
                    ->label('Percentage (basis points)')
                    ->numeric()->minValue(0)
                    ->visible(fn ($get) => $get('kind') === 'percentage')
                    ->disabled(),
            ])->columns(2),

            Section::make('Availability')->schema([
                Toggle::make('is_active')
                    ->default(false)
                    ->helperText('every_n_cash rules cannot be activated for launch.')
                    ->disabled(fn ($get) => $get('kind') === 'every_n_cash')
                    ->dehydrated(),
                TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('trigger_count')->label('Trigger'),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (RewardRule $r) => $r->amountFormatted()),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('rewards_count')->counts('rewards')->label('Rewards issued'),
            ])
            ->defaultSort('sort_order')
            ->filters([TernaryFilter::make('is_active')])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        // Hard-block reactivating legacy every_n_cash.
                        if (($data['kind'] ?? null) === 'every_n_cash') {
                            $data['is_active'] = false;
                        }

                        return $data;
                    })
                    ->after(function (RewardRule $record): void {
                        if ($record->kind === 'every_n_cash' && $record->is_active) {
                            $record->update(['is_active' => false]);
                            Notification::make()
                                ->title('Legacy every_n_cash cannot be activated')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        if (($data['kind'] ?? null) === 'every_n_cash') {
                            $data['is_active'] = false;
                        }

                        return $data;
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRewardRules::route('/'),
            'create' => Pages\CreateRewardRule::route('/create'),
            'edit' => Pages\EditRewardRule::route('/{record}/edit'),
        ];
    }
}
