<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('mfa.pending_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pendingId = $request->session()->get('mfa.pending_user_id');
        if (! $pendingId) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $throttleKey = 'mfa:'.$pendingId.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Please try again shortly.'),
            ]);
        }

        /** @var User|null $user */
        $user = User::whereKey($pendingId)->where('is_active', true)->first();
        if (! $user) {
            $request->session()->forget(['mfa.pending_user_id', 'mfa.remember']);

            return redirect()->route('login');
        }

        if (! $this->verifyOrConsumeCode($user, $data['code'])) {
            RateLimiter::hit($throttleKey, 60);
            AuditLogger::record('user.mfa_challenge_failed', $user, actor: $user);
            throw ValidationException::withMessages([
                'code' => __('That code was not valid.'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $remember = (bool) $request->session()->pull('mfa.remember', false);
        $request->session()->forget('mfa.pending_user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        AuditLogger::record('user.mfa_challenge_passed', $user, actor: $user);

        $hasPanel = $user->hasAnyRole(Role::panelRoles());
        $hasAmbassador = $user->hasRole(Role::Ambassador->value);
        if ($hasPanel && $hasAmbassador) {
            return redirect()->route('post-login.choose');
        }
        if ($hasPanel) {
            return redirect()->intended('/admin');
        }

        return redirect()->intended(route('ambassador.dashboard'));
    }

    private function verifyOrConsumeCode(User $user, #[SensitiveParameter] string $code): bool
    {
        $g2fa = new Google2FA;
        $secret = $user->getAppAuthenticationSecret();
        if ($secret && $g2fa->verifyKey($secret, trim($code), window: 2)) {
            return true;
        }

        // Fall back to single-use recovery codes.
        $codes = $user->getAppAuthenticationRecoveryCodes() ?? [];
        $normalised = strtolower(trim($code));
        foreach ($codes as $index => $stored) {
            if (hash_equals(strtolower($stored), $normalised)) {
                unset($codes[$index]);
                $user->saveAppAuthenticationRecoveryCodes(array_values($codes));

                return true;
            }
        }

        return false;
    }
}
