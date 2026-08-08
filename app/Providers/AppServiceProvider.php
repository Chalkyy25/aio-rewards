<?php

namespace App\Providers;

use App\Domain\Payouts\NotifyMissingPayoutMethod;
use App\Domain\Payouts\RevealedPayoutDetailsStore;
use App\Domain\Rewards\Events\RewardApproved;
use App\Listeners\SendAmbassadorWelcomeAfterVerified;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\ReferralClick;
use App\Policies\AmbassadorProfilePolicy;
use App\Policies\MemberPayoutProfilePolicy;
use App\Policies\ReferralClickPolicy;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped temporary holder for authorised payout reveals.
        $this->app->singleton(RevealedPayoutDetailsStore::class);
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
        Gate::policy(MemberPayoutProfile::class, MemberPayoutProfilePolicy::class);
        Gate::policy(ReferralClick::class, ReferralClickPolicy::class);

        // Send the welcome email exactly once, AFTER Laravel's own email
        // verification has succeeded. See SendAmbassadorWelcomeAfterVerified
        // for the idempotency guarantees.
        Event::listen(Verified::class, SendAmbassadorWelcomeAfterVerified::class);

        // Prompt Rewards Members (once) when a reward is approved but they
        // have not configured a payout destination yet.
        Event::listen(RewardApproved::class, NotifyMissingPayoutMethod::class);
    }
}
