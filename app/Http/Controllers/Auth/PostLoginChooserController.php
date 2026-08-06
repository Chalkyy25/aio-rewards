<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Landing page shown after a successful login when the user carries both
 * the ambassador role AND a panel role (admin / super_admin). Lets them
 * pick which surface to enter. Users with only one role never see this.
 */
class PostLoginChooserController extends Controller
{
    public function show(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $hasPanel = $user->hasAnyRole(Role::panelRoles());
        $hasAmbassador = $user->hasRole(Role::Ambassador->value);

        // If they somehow reach the chooser without dual roles, send them home.
        if (! ($hasPanel && $hasAmbassador)) {
            return redirect($hasPanel ? '/admin' : route('ambassador.dashboard'));
        }

        return view('auth.post-login-chooser');
    }
}
