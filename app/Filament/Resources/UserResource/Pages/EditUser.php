<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domain\Admin\AdminUserGuard;
use App\Filament\Resources\UserResource;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(fn () => AdminUserGuard::ensureNotLastSuperAdmin($this->record, 'delete'))
                ->using(fn ($record) => AdminUserGuard::delete($record, Auth::user())),
        ];
    }

    protected function beforeSave(): void
    {
        // If Super Admin role is being removed, verify a replacement exists.
        try {
            $incoming = collect($this->form->getState()['roles'] ?? [])->map(function ($v) {
                return is_array($v) ? ($v['name'] ?? $v) : $v;
            })->all();
            $isRemoving = $this->record->hasRole('super_admin')
                && ! in_array('super_admin', $incoming, true);
            if ($isRemoving) {
                AdminUserGuard::ensureNotLastSuperAdmin($this->record, 'demote');
            }
        } catch (DomainException $e) {
            Notification::make()->title('Blocked')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $roles = $this->record->getRoleNames()->all();
        AdminUserGuard::syncRoles($this->record, $roles, Auth::user());
    }
}
