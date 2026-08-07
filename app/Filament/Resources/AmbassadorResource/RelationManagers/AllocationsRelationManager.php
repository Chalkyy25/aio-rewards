<?php

namespace App\Filament\Resources\AmbassadorResource\RelationManagers;

use App\Models\ReferralAllocation;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Referral Allocations tab on the Rewards Member view. Strictly read-only
 * — allocation state changes flow via MilestoneProgressionService.
 */
class AllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    protected static ?string $title = 'Referral Allocations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('referral_conversion_id')->label('Conversion')->searchable(),
                TextColumn::make('reward.id')->label('Reward')->placeholder('—'),
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
            ])
            ->headerActions([])
            ->recordActions([ViewAction::make()->url(fn (ReferralAllocation $a) => \App\Filament\Resources\ReferralAllocationResource::getUrl('view', ['record' => $a]))])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
