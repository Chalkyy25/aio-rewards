<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Small helper that centralises where an authenticated user should land
 * from `/`, `/login`, `/activate`, and any post-authentication redirect.
 * Keeps the guest middleware, landing page, and post-login chooser in sync.
 */
final class AuthRedirects
{
    public static function homeFor(?User $user): string
    {
        if ($user === null) {
            return '/';
        }

        $hasPanel = $user->hasAnyRole(Role::panelRoles());
        $hasAmbassador = $user->hasRole(Role::Ambassador->value);

        if ($hasPanel && $hasAmbassador) {
            return route('post-login.choose');
        }
        if ($hasPanel) {
            return '/admin';
        }

        return route('ambassador.dashboard');
    }
}
