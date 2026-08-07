<?php

namespace App\Filament\Resources;

use App\Domain\Rewards\MilestoneProgressionService;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
use App\Filament\Resources\RewardResource\Pages;
use App\Models\ReferralAllocation;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                TextEntry::make('origin')->badge()
                    ->formatStateUsing(fn (Reward $r) => match ($r->origin) {
                        'milestone_claim' => 'Milestone claim',
                        'legacy_rule' => 'Legacy rule',
                        default => (string) $r->origin,
                    }),
                TextEntry::make('tier.title')->label('Milestone tier')->placeholder('—'),
                TextEntry::make('milestone_index')->label('Threshold snapshot'),
                TextEntry::make('cycle_number')->label('Cycle')->placeholder('—'),
                TextEntry::make('rule.name')->label('Legacy rule')->placeholder('—'),
                TextEntry::make('triggerConversion.id')->label('Trigger conversion')->placeholder('—'),
                TextEntry::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Reward $r) => $r->amountFormatted()),
                TextEntry::make('status')->badge()
                    ->color(fn (Reward $r) => $r->statusColor())
                    ->formatStateUsing(fn (Reward $r) => $r->statusLabel()),
                TextEntry::make('reject_disposition')->label('Rejection outcome')
                    ->formatStateUsing(fn (Reward $r) => match ($r->reject_disposition) {
                        'release' => 'Released allocations',
                        'consume' => 'Consumed cycle',
                        default => $r->reject_disposition ?? '—',
                    })->placeholder('—'),
            ])->columns(2),

            Section::make('Allocations')->schema([
                TextEntry::make('allocations_count')
                    ->label('Referrals allocated')
                    ->state(fn (Reward $r) => $r->allocations()->count()),
                TextEntry::make('active_allocations_count')
                    ->label('Currently active')
                    ->state(fn (Reward $r) => $r->allocations()->whereNotNull('active_marker')->count()),
                TextEntry::make('bonus_amount_minor')
                    ->label('Save & Grow bonus (config)')
                    ->state(fn (Reward $r) => '£'.number_format(
                        ((int) ($r->tier_snapshot['bonus_amount_minor'] ?? 0)) / 100, 2)),
                TextEntry::make('base_amount_minor')
                    ->label('Base amount (total − bonus)')
                    ->state(fn (Reward $r) => '£'.number_format(
                        max(0, $r->amount_minor - (int) ($r->tier_snapshot['bonus_amount_minor'] ?? 0)) / 100, 2)),
            ])->columns(4),

            Section::make('Funding referrals')->schema([
                RepeatableEntry::make('allocations')
                    ->label('')
                    ->schema([
                        TextEntry::make('referral_conversion_id')->label('Conversion'),
                        TextEntry::make('cycle_number')->label('Cycle'),
                        TextEntry::make('state')
                            ->state(fn (ReferralAllocation $a) => $a->isActive() ? 'Active' : 'Released')
                            ->badge()
                            ->color(fn (ReferralAllocation $a) => $a->isActive() ? 'success' : 'gray'),
                        TextEntry::make('allocated_at')->dateTime(),
                        TextEntry::make('released_at')->dateTime()->placeholder('—'),
                        TextEntry::make('release_reason')->placeholder('—'),
                    ])
                    ->columns(6)
                    ->contained(false),
            ])->visible(fn (Reward $r) => $r->allocations()->exists()),

            Section::make('Payout preference')->schema([
                TextEntry::make('preferred_payout_method')
                    ->label('Preferred payout method')
                    ->state(fn (Reward $r) => $r->ambassadorProfile?->payoutProfile?->preferred_method?->label() ?? 'Not configured')
                    ->badge()
                    ->color(fn (Reward $r) => $r->ambassadorProfile?->hasConfiguredPayoutMethod() ? 'success' : 'danger'),
                TextEntry::make('payout_configured')
                    ->label('Configured')
                    ->state(fn (Reward $r) => $r->ambassadorProfile?->hasConfiguredPayoutMethod() ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn (Reward $r) => $r->ambassadorProfile?->hasConfiguredPayoutMethod() ? 'success' : 'danger'),
                TextEntry::make('payout_warning')
                    ->label('Warning')
                    ->state('No payout method configured — contact the member or wait for them to add payout details before paying out, unless using an alternate manual method.')
                    ->visible(fn (Reward $r) => ! ($r->ambassadorProfile?->hasConfiguredPayoutMethod() ?? false)),
                TextEntry::make('payout_masked_summary')
                    ->label('Masked details')
                    ->state(function (Reward $r) {
                        $payout = $r->ambassadorProfile?->payoutProfile;
                        if (! $payout) {
                            return '—';
                        }

                        return match ($payout->preferred_method) {
                            PayoutMethod::BankTransfer => trim(($payout->account_holder_name ?? '').' · '
                                .($payout->maskedSortCode() ?? '').' · '
                                .($payout->maskedAccountNumber() ?? '')),
                            PayoutMethod::PayPal => (string) ($payout->paypal_email ?? '—'),
                            PayoutMethod::AccountCredit => 'Account Credit',
                        };
                    })
                    ->visible(fn (Reward $r) => $r->ambassadorProfile?->hasConfiguredPayoutMethod() ?? false),
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
                TextColumn::make('origin')->badge()->toggleable(),
                TextColumn::make('tier.title')->label('Tier')->placeholder('—')->searchable(),
                TextColumn::make('cycle_number')->label('Cycle')->sortable()->toggleable(),
                TextColumn::make('rule.name')->label('Legacy rule')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('milestone_index')->label('Threshold')->sortable()->toggleable(),
                TextColumn::make('amount_minor')->label('Amount')
                    ->formatStateUsing(fn (Reward $r) => $r->amountFormatted())->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (Reward $r) => $r->statusColor())
                    ->formatStateUsing(fn (Reward $r) => $r->statusLabel()),
                TextColumn::make('reject_disposition')->label('Rejection')->placeholder('—')
                    ->formatStateUsing(fn (Reward $r) => match ($r->reject_disposition) {
                        'release' => 'Released',
                        'consume' => 'Consumed',
                        default => '—',
                    })
                    ->toggleable(),
                TextColumn::make('approvedBy.email')->label('Approved by')->placeholder('—')->toggleable(),
                TextColumn::make('paidBy.email')->label('Paid by')->placeholder('—')->toggleable(),
                TextColumn::make('approved_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('milestone_tier_id')
                    ->label('Milestone tier')
                    ->options(fn () => RewardMilestoneTier::query()
                        ->orderBy('threshold')->pluck('title', 'id')->toArray()),
                SelectFilter::make('origin')->options([
                    'milestone_claim' => 'Milestone claim',
                    'legacy_rule' => 'Legacy rule',
                ]),
                TernaryFilter::make('save_and_grow')
                    ->label('Save & Grow bonus?')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('tier_snapshot')
                            ->whereRaw("JSON_EXTRACT(tier_snapshot, '$.bonus_amount_minor') > 0"),
                        false: fn ($q) => $q->where(function ($q) {
                            $q->whereNull('tier_snapshot')
                              ->orWhereRaw("JSON_EXTRACT(tier_snapshot, '$.bonus_amount_minor') = 0");
                        }),
                    ),
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
                    ->label('Reject (release)')
                    ->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->visible(fn (Reward $r) => in_array($r->status, ['pending_approval', 'approved'], true))
                    ->schema([Textarea::make('note')->required()->maxLength(500)
                        ->helperText('Correctable rejection — allocations are released so legitimate referrals become eligible again.')])
                    ->action(function (Reward $r, array $data, MilestoneProgressionService $ms, RewardsEngine $engine) {
                        if ($r->origin === 'milestone_claim') {
                            $ms->rejectAndRelease($r, Auth::user(), (string) $data['note']);
                        } else {
                            $engine->reject($r, Auth::user(), (string) $data['note']);
                        }
                        Notification::make()->title('Reward rejected & allocations released')->success()->send();
                    }),
                Action::make('rejectAndConsume')
                    ->label('Reject (consume cycle)')
                    ->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Reward $r) => $r->origin === 'milestone_claim' && in_array($r->status, ['pending_approval', 'approved'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Reject and consume the cycle')
                    ->modalDescription('The reward is rejected and the referrals stay consumed. Use only for confirmed disqualification or abuse.')
                    ->schema([Textarea::make('note')->required()->maxLength(500)])
                    ->action(function (Reward $r, array $data, MilestoneProgressionService $ms) {
                        $ms->rejectAndConsume($r, Auth::user(), (string) $data['note']);
                        Notification::make()->title('Reward rejected & cycle consumed')->success()->send();
                    }),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (Reward $r) => $r->status === 'approved')
                    ->modalHeading('Mark reward paid')
                    ->modalDescription(function (Reward $r) {
                        $payout = $r->ambassadorProfile?->payoutProfile;
                        if (! $payout || ! $payout->isConfigured()) {
                            return 'Warning: this Rewards Member has no payout method configured. Do not silently assume a destination — use an alternate manual method only if you have confirmed payment separately.';
                        }

                        return 'Preferred payout method: '.$payout->preferred_method->label()
                            .'. This records a manual payout only — no money is sent automatically.';
                    })
                    ->fillForm(function (Reward $r) {
                        $method = $r->ambassadorProfile?->payoutProfile?->preferred_method;

                        return [
                            'note' => $method
                                ? 'Preferred method: '.$method->label()
                                : null,
                        ];
                    })
                    ->schema([
                        Textarea::make('note')
                            ->label('Payout reference / note')
                            ->helperText('Record the payment reference. Prefills the member’s preferred method when available.')
                            ->maxLength(500),
                    ])
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
