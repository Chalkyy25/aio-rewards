<?php

use App\Support\AuthRedirects;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

// Guest routes (login, activate, register, password-reset, 2fa challenge) send
// authenticated users to the correct workspace instead of a hard "/" bounce.
RedirectIfAuthenticated::redirectUsing(
    fn (Request $request) => AuthRedirects::homeFor($request->user()),
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the Emergent preview ingress (and any production reverse
        // proxy). Trust all X-Forwarded-* headers so Laravel sees the
        // correct scheme + host and cookies + CSRF work end-to-end.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
        // Stripe posts raw JSON with an HMAC signature; CSRF and cookie
        // encryption must not touch the request body or headers.
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);
        $middleware->encryptCookies(except: ['aior_ref']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Customers must never see Laravel's raw "419 Page Expired" screen.
        // Regenerate the CSRF token, then bounce them back to the same
        // form with a friendly recovery message.
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            // Force a fresh token on the next render.
            $request->session()?->regenerateToken();
            $target = $request->headers->get('referer');
            $host = $request->getSchemeAndHttpHost();
            if (! is_string($target) || ! str_starts_with($target, $host)) {
                $target = route('login');
            }

            return redirect($target)->withInput($request->except('password', 'password_confirmation', 'provider_password'))
                ->with('status', 'Your session expired. Please try again.');
        });
    })->create();
