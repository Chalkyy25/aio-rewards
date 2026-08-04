<?php

namespace App\Policies;

use App\Enums\Role as RoleEnum;
use App\Models\Package;
use App\Models\User;

class PackagePolicy
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

    public function view(User $user, Package $package): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false; // admin/super-admin via before()
    }

    public function update(User $user, Package $package): bool
    {
        return false;
    }

    public function delete(User $user, Package $package): bool
    {
        return false;
    }
}
