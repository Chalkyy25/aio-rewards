<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\ReferralClick;
use App\Models\User;

class ReferralClickPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        // Read-only resource — even admins cannot mutate rows.
        if (in_array($ability, ['update', 'delete', 'create', 'restore', 'forceDelete'], true)) {
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

    public function view(User $user, ReferralClick $click): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReferralClick $click): bool
    {
        return false;
    }

    public function delete(User $user, ReferralClick $click): bool
    {
        return false;
    }
}
