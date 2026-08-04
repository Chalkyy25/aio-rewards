<?php

namespace App\Providers;

use App\Models\AmbassadorProfile;
use App\Models\ReferralClick;
use App\Policies\AmbassadorProfilePolicy;
use App\Policies\ReferralClickPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Behind the HTTPS preview / production reverse proxy, force
        // Laravel-generated URLs (including the Livewire endpoint and CSRF
        // form actions) to use HTTPS whenever APP_URL declares HTTPS. This
        // stops the browser from blocking mixed-content posts and stops
        // the auto-generated Livewire script tag from breaking cookies.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        Gate::policy(AmbassadorProfile::class, AmbassadorProfilePolicy::class);
        Gate::policy(ReferralClick::class, ReferralClickPolicy::class);
    }
}
