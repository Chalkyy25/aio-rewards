<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ReferralClickController;
use App\Livewire\AmbassadorActivation;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.welcome')->name('home');
Route::view('/packages', 'public.packages')->name('packages');

/* ---- Referral tracker ---- */
Route::get('/r/{code}', ReferralClickController::class)
    ->where('code', '[A-Za-z0-9_-]{4,40}')
    ->name('referral.click');

/* ---- Public ambassador auth + activation ---- */
Route::middleware('guest')->group(function () {
    Route::get('/activate', AmbassadorActivation::class)->name('activate');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

/* ---- Email verification ---- */
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

/* ---- Ambassador dashboard ---- */
Route::middleware(['auth', 'verified'])
    ->prefix('ambassador')
    ->name('ambassador.')
    ->group(function () {
        Route::view('/dashboard', 'ambassador.dashboard')->name('dashboard');
    });
