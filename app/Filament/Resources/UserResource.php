<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminUserGuard;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'admin-users';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Admin Users';

    protected static ?int $navigationSort = 80;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(RoleEnum::SuperAdmin->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->schema([
                TextInput::make('name')->required()->maxLength(190),
                TextInput::make('email')->email()->required()->maxLength(190)->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()->revealable()
                    ->minLength(12)
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                    ->helperText('Leave blank to keep the current password.'),
                Toggle::make('is_active')->default(true),
            ])->columns(2),

            Section::make('Roles')->schema([
                CheckboxList::make('roles')
                    ->relationship('roles', 'name')
                    ->options(fn () => SpatieRole::pluck('name', 'name'))
                    ->columns(2)
                    ->helperText('Every user needs at least one role. Super Admins cannot be left as the only remaining admin.'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->schema([
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('is_active')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Deactivated')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextEntry::make('created_at')->dateTime(),
            ])->columns(2),
            Section::make('Roles')->schema([
                TextEntry::make('roles.name')->badge()->separator(','),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('roles.name')->badge()->separator(', ')->label('Roles'),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('role')
                    ->label('Has role')
                    ->options(fn () => SpatieRole::pluck('name', 'name'))
                    ->query(fn ($q, array $data) => filled($data['value'] ?? null)
                        ? $q->whereHas('roles', fn ($r) => $r->where('name', $data['value']))
                        : $q),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->url(fn (User $r) => route('filament.admin.resources.admin-users.edit', $r)),
                Action::make('toggleActive')
                    ->label(fn (User $r) => $r->is_active ? 'Deactivate' : 'Reactivate')
                    ->icon(fn (User $r) => $r->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (User $r) => $r->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(function (User $r): void {
                        try {
                            AdminUserGuard::setActive($r, ! $r->is_active, Auth::user());
                            Notification::make()->title('User status updated')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title('Blocked')->body($e->getMessage())->danger()->send();
                        }
                    }),
                DeleteAction::make()
                    ->before(function (User $r): void {
                        AdminUserGuard::ensureNotLastSuperAdmin($r, 'delete');
                    })
                    ->using(fn (User $r) => AdminUserGuard::delete($r, Auth::user())),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
            'view' => Pages\ViewUser::route('/{record}'),
        ];
    }
}
