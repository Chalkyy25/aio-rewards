<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralAllocationResource\Pages;
use App\Models\ReferralAllocation;
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
 * Read-focused admin view of the referral-allocation ledger. Mutations
 * happen only via domain services (MilestoneProgressionService); Filament
 * intentionally exposes no create/edit/delete.
 */
class ReferralAllocationResource extends Resource
{
    protected static ?string $model = ReferralAllocation::class;

    protected static ?string $slug = 'referral-allocations';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Referral Allocations';

    protected static ?int $navigationSort = 42;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Allocation')->schema([
                TextEntry::make('id')->label('Allocation ID'),
                TextEntry::make('ambassadorProfile.user.email')->label('Rewards Member')->copyable(),
                TextEntry::make('referral_conversion_id')->label('Referral Conversion'),
                TextEntry::make('reward.id')->label('Reward')->placeholder('—'),
                TextEntry::make('reward.tier.title')->label('Milestone Tier')->placeholder('—'),
                TextEntry::make('cycle_number')->label('Cycle'),
                TextEntry::make('state')
                    ->state(fn (ReferralAllocation $a) => $a->isActive() ? 'Active' : 'Released')
                    ->badge()
                    ->color(fn (ReferralAllocation $a) => $a->isActive() ? 'success' : 'gray'),
                TextEntry::make('release_reason')->placeholder('—'),
            ])->columns(2),

            Section::make('Timeline')->schema([
                TextEntry::make('allocated_at')->dateTime(),
                TextEntry::make('released_at')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('updated_at')->label('Updated')->dateTime(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('ambassadorProfile.user.email')->label('Rewards Member')->searchable(),
                TextColumn::make('referral_conversion_id')->label('Conversion')->searchable(),
                TextColumn::make('reward.id')->label('Reward')->placeholder('—')->searchable(),
                TextColumn::make('reward.tier.title')->label('Tier')->placeholder('—')->toggleable(),
                TextColumn::make('cycle_number')->label('Cycle')->sortable(),
                TextColumn::make('state')
                    ->label('State')
                    ->state(fn (ReferralAllocation $a) => $a->isActive() ? 'Active' : 'Released')
                    ->badge()
                    ->color(fn (ReferralAllocation $a) => $a->isActive() ? 'success' : 'gray'),
                TextColumn::make('release_reason')->label('Reason')->placeholder('—')->toggleable(),
                TextColumn::make('allocated_at')->dateTime()->sortable(),
                TextColumn::make('released_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('allocated_at', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->options(['active' => 'Active', 'released' => 'Released'])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'active' => $query->whereNotNull('active_marker'),
                            'released' => $query->whereNull('active_marker'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('cycle_number')->label('Cycle')
                    ->options(fn () => \App\Models\ReferralAllocation::query()
                        ->select('cycle_number')->distinct()->orderBy('cycle_number')
                        ->pluck('cycle_number', 'cycle_number')->toArray()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralAllocations::route('/'),
            'view' => Pages\ViewReferralAllocation::route('/{record}'),
        ];
    }
}
