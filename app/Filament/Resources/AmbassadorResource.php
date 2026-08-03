<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AmbassadorResource\Pages;
use App\Models\AmbassadorProfile;
use App\Support\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AmbassadorResource extends Resource
{
    protected static ?string $model = AmbassadorProfile::class;

    protected static ?string $slug = 'ambassadors';

    protected static ?string $recordTitleAttribute = 'referral_code';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Ambassadors';

    public static function form(Schema $schema): Schema
    {
        // Ambassador records are not directly editable via a form in v1.
        // View / flag / unflag actions are exposed via the table + infolist.
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->schema([
                TextEntry::make('user.name')->label('Name'),
                TextEntry::make('user.email')->label('Email')->copyable(),
                TextEntry::make('user.email_verified_at')->label('Email verified')->dateTime()->placeholder('Not verified'),
                TextEntry::make('user.is_active')->label('Active')->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ])->columns(2),

            Section::make('Referral')->schema([
                TextEntry::make('referral_code')->copyable()->weight('bold'),
                TextEntry::make('referral_url')->label('Referral URL')
                    ->state(fn (AmbassadorProfile $r) => $r->referralUrl())->copyable(),
                TextEntry::make('activated_at')->dateTime(),
            ])->columns(2),

            Section::make('Provider verification')->schema([
                TextEntry::make('provider_username'),
                TextEntry::make('provider_driver_key')->label('Driver'),
                TextEntry::make('provider_customer_ref')->label('Provider ref')->placeholder('—'),
            ])->columns(3),

            Section::make('Review')->schema([
                TextEntry::make('flagged_for_review')->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state) => $state ? 'Flagged' : 'Clear'),
                TextEntry::make('flagged_reason')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('provider_username')->label('Provider username')->searchable()->toggleable(),
                TextColumn::make('referral_code')->label('Code')->badge()->searchable(),
                IconColumn::make('user.is_active')->label('Active')->boolean(),
                IconColumn::make('flagged_for_review')->label('Flagged')->boolean(),
                TextColumn::make('activated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('activated_at', 'desc')
            ->filters([
                TernaryFilter::make('flagged_for_review')->label('Flagged for review'),
                TernaryFilter::make('user.is_active')->label('Account active')
                    ->queries(
                        true: fn ($q) => $q->whereRelation('user', 'is_active', true),
                        false: fn ($q) => $q->whereRelation('user', 'is_active', false),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('flag')
                    ->label('Flag for review')
                    ->icon('heroicon-o-flag')
                    ->color('warning')
                    ->visible(fn (AmbassadorProfile $r) => ! $r->flagged_for_review)
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->action(function (AmbassadorProfile $r, array $data): void {
                        $r->update([
                            'flagged_for_review' => true,
                            'flagged_reason' => $data['reason'],
                        ]);
                        AuditLogger::record(
                            action: 'ambassador.flagged',
                            subject: $r,
                            after: ['reason' => $data['reason']],
                        );
                        Notification::make()->title('Flagged for review')->success()->send();
                    }),
                Action::make('unflag')
                    ->label('Clear flag')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AmbassadorProfile $r) => $r->flagged_for_review)
                    ->requiresConfirmation()
                    ->action(function (AmbassadorProfile $r): void {
                        $before = ['reason' => $r->flagged_reason];
                        $r->update(['flagged_for_review' => false, 'flagged_reason' => null]);
                        AuditLogger::record(
                            action: 'ambassador.unflagged',
                            subject: $r,
                            before: $before,
                        );
                        Notification::make()->title('Flag cleared')->success()->send();
                    }),
                Action::make('deactivateUser')
                    ->label('Deactivate account')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (AmbassadorProfile $r) => (bool) $r->user->is_active)
                    ->requiresConfirmation()
                    ->action(function (AmbassadorProfile $r): void {
                        $r->user->update(['is_active' => false]);
                        AuditLogger::record(
                            action: 'user.deactivated',
                            subject: $r->user,
                            actor: Auth::user(),
                        );
                        Notification::make()->title('User deactivated')->success()->send();
                    }),
                Action::make('activateUser')
                    ->label('Reactivate account')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (AmbassadorProfile $r) => ! $r->user->is_active)
                    ->requiresConfirmation()
                    ->action(function (AmbassadorProfile $r): void {
                        $r->user->update(['is_active' => true]);
                        AuditLogger::record(
                            action: 'user.reactivated',
                            subject: $r->user,
                            actor: Auth::user(),
                        );
                        Notification::make()->title('User reactivated')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAmbassadors::route('/'),
            'view' => Pages\ViewAmbassador::route('/{record}'),
        ];
    }
}
