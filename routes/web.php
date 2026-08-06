<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\AmbassadorDashboardController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\ReferralClickController;
use App\Http\Controllers\StripeWebhookController;
use App\Livewire\AmbassadorActivation;
use App\Models\Package;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.welcome')->name('home');

Route::get('/packages', function () {
    return view('public.packages', ['packages' => Package::where('is_active', true)->orderBy('sort_order')->get()]);
})->name('packages');

Route::get('/r/{code}', ReferralClickController::class)->where('code', '[A-Za-z0-9_-]{4,40}')->name('referral.click');

// Checkout
Route::get('/checkout/{slug}/details', [CheckoutController::class, 'details'])->name('checkout.details');
Route::post('/checkout/{slug}/details', [CheckoutController::class, 'submitDetails'])->middleware('throttle:20,1');
Route::get('/checkout/{slug}/review', [CheckoutController::class, 'review'])->name('checkout.review');
Route::post('/checkout/{slug}/pay', [CheckoutController::class, 'pay'])->middleware('throttle:10,1')->name('checkout.pay');
Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');
Route::get('/checkout/cancel', [CheckoutController::class, 'cancel'])->name('checkout.cancel');

// Public order status page — token is opaque, no PII in the URL.
Route::get('/order/{token}', [OrderStatusController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('order.status');

// Stripe webhook (no CSRF, no session)
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

// Public ambassador auth
Route::middleware('guest')->group(function () {
    Route::get('/activate', AmbassadorActivation::class)->name('activate');
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.update');

    Route::get('/login/2fa', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'show'])->name('login.challenge');
    Route::post('/login/2fa', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'verify'])
        ->middleware('throttle:10,1')->name('login.challenge.submit');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

Route::get('/post-login', [\App\Http\Controllers\Auth\PostLoginChooserController::class, 'show'])
    ->middleware('auth')
    ->name('post-login.choose');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
});

Route::middleware(['auth', 'verified'])->prefix('ambassador')->name('ambassador.')->group(function () {
    Route::get('/dashboard', [AmbassadorDashboardController::class, 'show'])->name('dashboard');
    Route::get('/security', \App\Livewire\AmbassadorSecurity::class)->name('security');
});
