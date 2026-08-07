<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RewardMilestoneTierResource\Pages;
use App\Models\RewardMilestoneTier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class RewardMilestoneTierResource extends Resource
{
    protected static ?string $model = RewardMilestoneTier::class;

    protected static ?string $slug = 'milestone-tiers';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Milestone Tiers';

    protected static ?int $navigationSort = 39;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tier definition')->schema([
                TextInput::make('title')->required()->maxLength(190),
                Textarea::make('description')->maxLength(1000)->rows(2),
                TextInput::make('threshold')
                    ->numeric()->minValue(1)->required()
                    ->helperText('Approved referrals required to reach this tier.')
                    ->rule(function ($get, $record) {
                        return Rule::unique('reward_milestone_tiers', 'threshold')
                            ->where('is_active', 1)
                            ->ignore($record?->getKey());
                    }),
                TextInput::make('total_reward_amount_minor')
                    ->label('Total reward (minor units, pence)')
                    ->numeric()->minValue(0)->required()
                    ->helperText('£110.00 → 11000.'),
                TextInput::make('bonus_amount_minor')
                    ->label('Save & Grow bonus (minor units)')
                    ->numeric()->minValue(0)->default(0)
                    ->helperText('Display-only figure highlighted to the member.')
                    ->rules([
                        fn (\Filament\Schemas\Components\Utilities\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                            $total = (int) ($get('total_reward_amount_minor') ?? 0);
                            if ((int) $value > $total) {
                                $fail('Bonus cannot exceed the total reward amount.');
                            }
                        },
                    ]),
                TextInput::make('currency')->required()->default('gbp')->maxLength(3),
                TextInput::make('display_order')->numeric()->default(0),
            ])->columns(2),

            Section::make('Availability')->schema([
                Toggle::make('is_active')->default(true)
                    ->helperText('Inactive tiers are never evaluated or shown.'),
                Toggle::make('is_visible')->default(true)
                    ->helperText('Hidden tiers do not appear on the member page.'),
                Toggle::make('is_claimable')->default(true)
                    ->helperText('Non-claimable tiers are displayed but cannot be cashed out.'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_order')->label('#')->sortable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('threshold')->sortable(),
                TextColumn::make('total_reward_amount_minor')->label('Amount')
                    ->formatStateUsing(fn (RewardMilestoneTier $t) => $t->amountFormatted()),
                TextColumn::make('bonus_amount_minor')->label('Bonus')
                    ->formatStateUsing(fn (RewardMilestoneTier $t) => $t->bonusFormatted()),
                TextColumn::make('currency'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_visible')->label('Visible')->boolean(),
                IconColumn::make('is_claimable')->label('Claimable')->boolean(),
            ])
            ->defaultSort('display_order')
            ->filters([
                TernaryFilter::make('is_active'),
                TernaryFilter::make('is_visible'),
                TernaryFilter::make('is_claimable'),
            ])
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
            'index' => Pages\ListRewardMilestoneTiers::route('/'),
            'create' => Pages\CreateRewardMilestoneTier::route('/create'),
            'edit' => Pages\EditRewardMilestoneTier::route('/{record}/edit'),
        ];
    }
}
