<?php

namespace App\Filament\Resources\AmbassadorResource\RelationManagers;

use App\Models\Reward;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only Rewards tab on the Rewards Member view page. Approval /
 * payment / rejection continue to go through {@see \App\Filament\Resources\RewardResource}
 * so all sensitive mutations are audited by the RewardsEngine /
 * MilestoneProgressionService.
 */
class RewardsRelationManager extends RelationManager
{
    protected static string $relationship = 'rewards';

    protected static ?string $title = 'Rewards';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('tier.title')->label('Milestone')->placeholder('—'),
                TextColumn::make('milestone_index')->label('Threshold'),
                TextColumn::make('cycle_number')->label('Cycle')->placeholder('—'),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Reward $r) => $r->amountFormatted()),
                TextColumn::make('status')->badge()
                    ->color(fn (Reward $r) => $r->statusColor())
                    ->formatStateUsing(fn (Reward $r) => $r->statusLabel()),
                TextColumn::make('approved_at')->dateTime()->toggleable(),
                TextColumn::make('paid_at')->dateTime()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending_approval' => 'Awaiting approval',
                    'approved' => 'Awaiting payment',
                    'paid' => 'Paid',
                    'rejected' => 'Rejected',
                    'reversed' => 'Reversed',
                ]),
            ])
            ->headerActions([])
            ->recordActions([ViewAction::make()->url(fn (Reward $r) => \App\Filament\Resources\RewardResource::getUrl('view', ['record' => $r]))])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
