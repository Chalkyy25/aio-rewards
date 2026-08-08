<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;

class AmbassadorProfilePolicy
{
    public function before(User $user, string $ability): ?bool
    {
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

    public function view(User $user, AmbassadorProfile $profile): bool
    {
        if ($user->id === $profile->user_id) {
            return true;
        }

        // Support may inspect Rewards Member records (masked payout details only).
        return $user->hasRole(RoleEnum::Support->value);
    }

    public function update(User $user, AmbassadorProfile $profile): bool
    {
        // Ambassadors cannot self-edit sensitive profile fields; admins bypass via `before`.
        return false;
    }

    public function delete(User $user, AmbassadorProfile $profile): bool
    {
        return false;
    }
}
