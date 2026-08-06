<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\Reward;
use App\Models\User;

class RewardPolicy
{
    public function before(User $user, string $ability): ?bool
    {
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

    public function view(User $user, Reward $r): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Reward $r): bool
    {
        return false;
    }
}
