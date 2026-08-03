<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
|
| Phase 0 exposes only the landing page and a placeholder ambassador
| dashboard route. Referral tracking (/r/{code}), activation, checkout
| and the ambassador dashboard proper are wired up in later phases.
|
*/

Route::view('/', 'public.welcome')->name('home');

Route::middleware(['auth', 'verified'])
    ->prefix('ambassador')
    ->name('ambassador.')
    ->group(function () {
        Route::view('/dashboard', 'ambassador.dashboard')->name('dashboard');
    });
