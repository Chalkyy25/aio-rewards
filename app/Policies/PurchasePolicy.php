<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        // Purchases are never editable via Filament outside the fulfilment action.
        if (in_array($ability, ['create', 'delete', 'restore', 'forceDelete'], true)) {
            return false;
        }
        if ($user->hasAnyRole([RoleEnum::Admin->value, RoleEnum::SuperAdmin->value])) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            RoleEnum::Support->value,
            RoleEnum::Admin->value,
            RoleEnum::SuperAdmin->value,
        ]);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        // Only admins may mark fulfilled (via action), granted through before().
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return false;
    }
}
