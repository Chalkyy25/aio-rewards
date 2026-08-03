<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(function ($request) {
            $user = $request->user();

            return $user instanceof User
                && $user->is_active
                && $user->hasRole(Role::SuperAdmin->value);
        });
    }

    /**
     * Horizon dashboard authorisation gate — Super Admin only.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user instanceof User
                && $user->is_active
                && $user->hasRole(Role::SuperAdmin->value);
        });
    }
}
