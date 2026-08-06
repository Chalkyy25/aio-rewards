<?php

namespace App\Filament\Resources;

use App\Domain\Operations\OperationsWriter;
use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Enums\OperationsType;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\OperationsItemResource\Pages;
use App\Models\OperationsItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class OperationsItemResource extends Resource
{
    protected static ?string $model = OperationsItem::class;

    protected static ?string $slug = 'operations';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationLabel = 'Operations Centre';

    protected static ?string $modelLabel = 'Work item';

    protected static ?string $pluralModelLabel = 'Work items';

    protected static ?string $recordTitleAttribute = 'title';

    /** Sit at the very top of the sidebar. */
    protected static ?int $navigationSort = -100;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(RoleEnum::panelRoles()) ?? false;
    }

    /** Sidebar badge — count of open items. */
    public static function getNavigationBadge(): ?string
    {
        $count = OperationsItem::query()
            ->whereIn('status', OperationsStatus::openValues())
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $critical = OperationsItem::query()
            ->whereIn('status', OperationsStatus::openValues())
            ->where('priority', OperationsPriority::Critical->value)
            ->exists();

        return $critical ? 'danger' : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('priority')->options(OperationsPriority::options())->required(),
            Select::make('status')->options(OperationsStatus::options())->required(),
            Select::make('assigned_user_id')
                ->label('Assigned admin')
                ->searchable()
                ->options(fn () => self::adminOptions()),
            Textarea::make('resolution_notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (OperationsItem $r) => Pages\ViewOperationsItem::getUrl(['record' => $r]))
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn ($state) => OperationsPriority::tryFrom((string) $state)?->label() ?? $state)
                    ->color(fn ($state) => OperationsPriority::tryFrom((string) $state)?->color() ?? 'gray')
                    ->sortable()
                    ->extraCellAttributes(['data-testid' => 'ops-col-priority']),
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => OperationsType::tryFrom((string) $state)?->label() ?? $state)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('title')->searchable()->limit(80),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => OperationsStatus::tryFrom((string) $state)?->label() ?? $state)
                    ->color(fn ($state) => OperationsStatus::tryFrom((string) $state)?->color() ?? 'gray'),
                TextColumn::make('escalation_level')->label('Esc.')->numeric()->sortable(),
                TextColumn::make('assignedTo.email')->label('Assignee')->toggleable()->searchable(),
                TextColumn::make('due_at')->label('Due')->since()->toggleable(),
                TextColumn::make('created_at')->label('Opened')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OperationsStatus::options())->multiple(),
                SelectFilter::make('priority')->options(OperationsPriority::options())->multiple(),
                SelectFilter::make('type')->options(collect(OperationsType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all())->multiple(),
                SelectFilter::make('assigned_user_id')
                    ->label('Assignee')
                    ->options(fn () => self::adminOptions() + ['none' => '— Unassigned —'])
                    ->query(function ($q, array $data) {
                        $v = $data['value'] ?? null;
                        if ($v === null || $v === '') {
                            return $q;
                        }
                        if ($v === 'none') {
                            return $q->whereNull('assigned_user_id');
                        }

                        return $q->where('assigned_user_id', $v);
                    }),
                TernaryFilter::make('open')
                    ->label('Open only')
                    ->placeholder('All')
                    ->trueLabel('Open only')
                    ->falseLabel('Closed only')
                    ->queries(
                        true: fn ($q) => $q->whereIn('status', OperationsStatus::openValues()),
                        false: fn ($q) => $q->whereIn('status', [OperationsStatus::Resolved->value, OperationsStatus::Dismissed->value]),
                        blank: fn ($q) => $q,
                    )
                    ->default(true),
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('due_at')->where('due_at', '<', now())->whereIn('status', OperationsStatus::openValues()),
                        false: fn ($q) => $q,
                        blank: fn ($q) => $q,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('markSeen')
                    ->label('Mark seen')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (OperationsItem $r) => $r->isOpen() && $r->first_viewed_at === null)
                    ->action(function (OperationsItem $r) {
                        app(OperationsWriter::class)->markSeen($r);
                        Notification::make()->title('Marked seen')->success()->send();
                    }),
                Action::make('start')
                    ->label('Start')
                    ->icon('heroicon-o-play')
                    ->visible(fn (OperationsItem $r) => $r->isOpen() && $r->statusEnum() !== OperationsStatus::InProgress)
                    ->action(function (OperationsItem $r) {
                        app(OperationsWriter::class)->startProgress($r);
                        Notification::make()->title('Started')->success()->send();
                    }),
                Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        Select::make('assigned_user_id')
                            ->label('Assign to')
                            ->options(fn () => self::adminOptions())
                            ->required(),
                    ])
                    ->action(function (OperationsItem $r, array $data) {
                        $u = User::find($data['assigned_user_id']);
                        if ($u) {
                            app(OperationsWriter::class)->assign($r, $u);
                            Notification::make()->title('Assigned to '.$u->email)->success()->send();
                        }
                    }),
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->schema([
                        Textarea::make('resolution_notes')->label('Resolution notes')->rows(3),
                    ])
                    ->visible(fn (OperationsItem $r) => $r->isOpen())
                    ->action(function (OperationsItem $r, array $data) {
                        app(OperationsWriter::class)->resolve($r, $data['resolution_notes'] ?? null);
                        Notification::make()->title('Resolved')->success()->send();
                    }),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->schema([
                        Textarea::make('resolution_notes')->label('Reason')->rows(2)->required(),
                    ])
                    ->visible(fn (OperationsItem $r) => $r->isOpen())
                    ->action(function (OperationsItem $r, array $data) {
                        app(OperationsWriter::class)->dismiss($r, $data['resolution_notes']);
                        Notification::make()->title('Dismissed')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkAssign')
                        ->label('Assign selected')
                        ->icon('heroicon-o-user-plus')
                        ->schema([
                            Select::make('assigned_user_id')
                                ->label('Assign to')
                                ->options(fn () => self::adminOptions())
                                ->required(),
                        ])
                        ->action(function ($records, array $data) {
                            $u = User::find($data['assigned_user_id']);
                            if (! $u) {
                                return;
                            }
                            foreach ($records as $r) {
                                app(OperationsWriter::class)->assign($r, $u);
                            }
                            Notification::make()->title('Assigned '.count($records).' items to '.$u->email)->success()->send();
                        }),
                    BulkAction::make('bulkResolve')
                        ->label('Resolve selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->schema([
                            Textarea::make('resolution_notes')->label('Resolution notes')->rows(2),
                        ])
                        ->action(function ($records, array $data) {
                            $notes = $data['resolution_notes'] ?? null;
                            foreach ($records as $r) {
                                if ($r->isOpen()) {
                                    app(OperationsWriter::class)->resolve($r, $notes);
                                }
                            }
                            Notification::make()->title('Resolved '.count($records).' items')->success()->send();
                        }),
                    BulkAction::make('bulkDismiss')
                        ->label('Dismiss selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->schema([
                            Textarea::make('resolution_notes')->label('Reason')->rows(2)->required(),
                        ])
                        ->action(function ($records, array $data) {
                            foreach ($records as $r) {
                                if ($r->isOpen()) {
                                    app(OperationsWriter::class)->dismiss($r, $data['resolution_notes']);
                                }
                            }
                            Notification::make()->title('Dismissed '.count($records).' items')->success()->send();
                        }),
                    DeleteBulkAction::make()->visible(fn () => Auth::user()?->hasRole(RoleEnum::SuperAdmin->value) ?? false),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationsItems::route('/'),
            'view' => Pages\ViewOperationsItem::route('/{record}'),
        ];
    }

    /** @return array<int|string,string> */
    protected static function adminOptions(): array
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', RoleEnum::panelRoles()))
            ->where('is_active', true)
            ->orderBy('email')
            ->pluck('email', 'id')
            ->all();
    }
}
