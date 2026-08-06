<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Domain\Admin\AdminUserGuard;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        // Ensure role assignment goes through the guard (audit + sanity).
        $roles = $this->record->getRoleNames()->all();
        AdminUserGuard::syncRoles($this->record, $roles, Auth::user());
    }
}
