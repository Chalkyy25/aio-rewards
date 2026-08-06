<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): \Illuminate\Http\RedirectResponse|View
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect()->intended(route('ambassador.dashboard'));
        }

        return view('auth.verify-email');
    }

    /**
     * Handles both the "authenticated session on this device" case and the
     * "verified on another device / browser without a session here" case.
     *
     * The signed URL is authoritative: the `signed` middleware validates the
     * signature and the {hash} segment matches sha1($user->getEmailForVerification())
     * (same rule as Laravel's default), so if we're standing in this method the
     * URL has been proven to belong to that specific user.
     */
    public function verify(Request $request): RedirectResponse|View|\Symfony\Component\HttpFoundation\Response
    {
        // Case A: the browser hitting this URL is already authenticated as
        // the target user (or a super admin standing in) — behave exactly like
        // Laravel's default EmailVerificationRequest.
        $authed = $request->user();
        if ($authed !== null && (string) $authed->getKey() === (string) $request->route('id')) {
            return $this->verifyForAuthenticatedUser($request);
        }

        // Case B: no session for the target user on this device (verification
        // performed on another device). We still trust the signed URL.
        if (! URL::hasValidSignature($request)) {
            abort(403);
        }

        $user = User::query()->whereKey($request->route('id'))->first();
        if ($user === null) {
            abort(404);
        }

        // Hash from the URL must match a hash of the current email — the same
        // guarantee Laravel's default request enforces.
        if (! hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->view('auth.verification-success');
    }

    private function verifyForAuthenticatedUser(Request $request): RedirectResponse
    {
        /** @var EmailVerificationRequest $r */
        $r = app(EmailVerificationRequest::class);

        if ($r->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('ambassador.dashboard').'?verified=1');
        }

        if ($r->user()->markEmailAsVerified()) {
            event(new Verified($r->user()));
        }

        return redirect()->intended(route('ambassador.dashboard').'?verified=1');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('ambassador.dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('A new verification email has been sent.'));
    }
}
