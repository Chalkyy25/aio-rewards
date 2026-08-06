<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RewardRuleResource\Pages;
use App\Models\RewardRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RewardRuleResource extends Resource
{
    protected static ?string $model = RewardRule::class;

    protected static ?string $slug = 'reward-rules';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Reward Rules';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rule')->schema([
                TextInput::make('name')->required()->maxLength(190),
                Select::make('kind')->required()->default('every_n_cash')->options([
                    'every_n_cash' => 'Every X approved conversions → fixed cash',
                    'percentage' => 'Percentage of sale (coming soon)',
                    'lifetime_revenue' => 'Lifetime revenue bonus (coming soon)',
                ])->live(),
                TextInput::make('trigger_count')
                    ->label('Every N approved conversions')
                    ->numeric()->minValue(1)->default(5)->required()
                    ->visible(fn ($get) => $get('kind') === 'every_n_cash'),
                TextInput::make('amount_minor')
                    ->label('Reward amount (minor units, pence)')
                    ->numeric()->minValue(0)->required()
                    ->helperText('£50.00 → 5000.')
                    ->visible(fn ($get) => $get('kind') === 'every_n_cash'),
                TextInput::make('currency')->required()->default('gbp')->maxLength(3),
                TextInput::make('percentage_bps')
                    ->label('Percentage (basis points)')
                    ->numeric()->minValue(0)
                    ->visible(fn ($get) => $get('kind') === 'percentage'),
            ])->columns(2),

            Section::make('Availability')->schema([
                Toggle::make('is_active')->default(true),
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
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
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
