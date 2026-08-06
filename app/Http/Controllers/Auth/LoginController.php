<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));

            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Please try again shortly.'),
            ]);
        }

        $remember = $request->boolean('remember');

        if (! Auth::attempt(['email' => Str::lower($credentials['email']), 'password' => $credentials['password'], 'is_active' => true], $remember)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        $hasPanel = $user->hasAnyRole(Role::panelRoles());
        $hasAmbassador = $user->hasRole(Role::Ambassador->value);

        // Dual-role users pick where to land.
        if ($hasPanel && $hasAmbassador) {
            return redirect()->route('post-login.choose');
        }

        if ($hasPanel) {
            return redirect()->intended('/admin');
        }

        return redirect()->intended(route('ambassador.dashboard'));
    }
}
