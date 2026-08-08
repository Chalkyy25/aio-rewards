<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\MemberPayoutProfile;
use App\Models\User;

class MemberPayoutProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(RoleEnum::panelRoles());
    }

    public function view(User $user, MemberPayoutProfile $profile): bool
    {
        if ($user->id === $profile->user_id) {
            return true;
        }

        return $user->hasAnyRole(RoleEnum::panelRoles());
    }

    public function update(User $user, MemberPayoutProfile $profile): bool
    {
        return $user->id === $profile->user_id
            && $user->hasRole(RoleEnum::Ambassador->value)
            && (bool) $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleEnum::Ambassador->value)
            && (bool) $user->is_active
            && $user->ambassadorProfile !== null;
    }

    /**
     * Full plaintext bank (or legacy PayPal) destination reveal — Admin / Super Admin only.
     * Support may see method + masked details, never the reveal action.
     */
    public function reveal(User $user, MemberPayoutProfile $profile): bool
    {
        return $user->hasAnyRole([
            RoleEnum::Admin->value,
            RoleEnum::SuperAdmin->value,
        ]) && (bool) $user->is_active;
    }
}
