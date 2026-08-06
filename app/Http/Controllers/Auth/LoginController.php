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

        // First stage: verify credentials without persisting the login when
        // the account has app-level MFA turned on. We flip the log-in on
        // ourselves only after the TOTP challenge succeeds.
        $email = Str::lower($credentials['email']);
        $user = User::query()->where('email', $email)->where('is_active', true)->first();

        if (! $user || ! \Illuminate\Support\Facades\Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        // Step-up: ambassador-level MFA is opt-in. Panel roles come here too;
        // Filament's own MFA still kicks in when they land on /admin. This
        // handles the app-level /login form only.
        if ($user->mfa_enabled && $user->mfaConfigured() && ! $user->hasAnyRole(\App\Enums\Role::panelRoles())) {
            $request->session()->put('mfa.pending_user_id', $user->id);
            $request->session()->put('mfa.remember', $remember);

            return redirect()->route('login.challenge');
        }

        Auth::login($user, $remember);
        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

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
