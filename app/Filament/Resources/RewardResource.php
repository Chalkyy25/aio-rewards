<?php

namespace App\Filament\Resources;

use App\Domain\Rewards\RewardsEngine;
use App\Filament\Resources\RewardResource\Pages;
use App\Models\Reward;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RewardResource extends Resource
{
    protected static ?string $model = Reward::class;

    protected static ?string $slug = 'rewards';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Rewards';

    protected static ?int $navigationSort = 41;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reward')->schema([
                TextEntry::make('id')->label('Reward ID'),
                TextEntry::make('ambassadorProfile.user.name')->label('Ambassador'),
                TextEntry::make('ambassadorProfile.user.email')->label('Email')->copyable(),
                TextEntry::make('rule.name')->label('Rule')->placeholder('—'),
                TextEntry::make('milestone_index')->label('Milestone'),
                TextEntry::make('triggerConversion.id')->label('Trigger conversion')->placeholder('—'),
                TextEntry::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Reward $r) => $r->amountFormatted()),
                TextEntry::make('status')->badge()
                    ->color(fn (Reward $r) => $r->statusColor())
                    ->formatStateUsing(fn (Reward $r) => $r->statusLabel()),
            ])->columns(2),

            Section::make('Timeline')->schema([
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                TextEntry::make('rejected_at')->dateTime()->placeholder('—'),
                TextEntry::make('reversed_at')->dateTime()->placeholder('—'),
            ])->columns(3),

            Section::make('People')->schema([
                TextEntry::make('approvedBy.email')->label('Approved by')->placeholder('—'),
                TextEntry::make('paidBy.email')->label('Paid by')->placeholder('—'),
                TextEntry::make('rejectedBy.email')->label('Rejected by')->placeholder('—'),
                TextEntry::make('reversedBy.email')->label('Reversed by')->placeholder('—'),
            ])->columns(2),

            Section::make('Note')->schema([
                TextEntry::make('note')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('ambassadorProfile.user.email')->label('Ambassador')->searchable(),
                TextColumn::make('rule.name')->label('Rule')->placeholder('—')->searchable(),
                TextColumn::make('milestone_index')->label('Milestone')->sortable(),
                TextColumn::make('trigger_conversion_id')->label('Trigger conv.')->placeholder('—')->toggleable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Reward $r) => $r->amountFormatted())->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (Reward $r) => $r->statusColor())
                    ->formatStateUsing(fn (Reward $r) => $r->statusLabel()),
                TextColumn::make('approvedBy.email')->label('Approved by')->placeholder('—')->toggleable(),
                TextColumn::make('paidBy.email')->label('Paid by')->placeholder('—')->toggleable(),
                TextColumn::make('approved_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending_approval' => 'Pending approval',
                    'approved' => 'Approved',
                    'paid' => 'Paid',
                    'rejected' => 'Rejected',
                    'reversed' => 'Reversed',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (Reward $r) => $r->status === 'pending_approval')
                    ->requiresConfirmation()
                    ->action(function (Reward $r, RewardsEngine $engine) {
                        if ($engine->approve($r, Auth::user())) {
                            Notification::make()->title('Reward approved')->success()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')->color('gray')
                    ->visible(fn (Reward $r) => in_array($r->status, ['pending_approval', 'approved'], true))
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action(function (Reward $r, array $data, RewardsEngine $engine) {
                        $engine->reject($r, Auth::user(), $data['note'] ?? null);
                        Notification::make()->title('Reward rejected')->success()->send();
                    }),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (Reward $r) => $r->status === 'approved')
                    ->schema([Textarea::make('note')->label('Payout reference / note')->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(function (Reward $r, array $data, RewardsEngine $engine) {
                        $engine->markPaid($r, Auth::user(), $data['note'] ?? null);
                        Notification::make()->title('Reward marked paid')->success()->send();
                    }),
                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (Reward $r) => $r->status !== 'reversed')
                    ->schema([Textarea::make('note')->maxLength(500)])
                    ->action(function (Reward $r, array $data, RewardsEngine $engine) {
                        $engine->reverse($r, Auth::user(), $data['note'] ?? null);
                        Notification::make()->title('Reward reversed')->success()->send();
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
            'index' => Pages\ListRewards::route('/'),
            'view' => Pages\ViewReward::route('/{record}'),
        ];
    }
}
