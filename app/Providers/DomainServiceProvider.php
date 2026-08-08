<?php

namespace App\Providers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\AioIptvVerificationDriver;
use App\Domain\Provider\Drivers\DisabledVerificationDriver;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Domain\Provider\Drivers\XtreamVerificationDriver;
use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Domain\Settings\SettingsRepository;
use App\Listeners\EvaluateMilestoneUnlockForApprovedConversion;
use App\Listeners\EvaluateRewardsForApprovedConversion;
use App\Listeners\MarkMilestoneUnlockNotificationSent;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Domain\Settings\SettingsRepository::class);

        $this->app->singleton(CustomerVerificationContract::class, function (Application $app) {
            $key = (string) config('provider.driver');

            // FakeVerificationDriver MUST NOT be resolvable from the container
            // outside the testing environment. It exists only for automated
            // tests, which bind an instance explicitly via
            //   $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
            // Any production / staging / preview / local configuration that
            // still references the fake driver fails loudly here — there is
            // no silent fallback to fake verification.
            if ($key === 'fake' && ! $app->environment('testing')) {
                throw new InvalidArgumentException(
                    'Fake verification driver is not available outside the testing environment.'
                );
            }

            /** @var SettingsRepository $settings */
            $settings = $app->make(SettingsRepository::class);
            $verificationEnabled = (bool) (int) ($settings->value('provider.enabled') ?? '1');

            // Maintenance mode: verification toggled OFF in Settings. The
            // DisabledVerificationDriver now REFUSES activations (throws
            // ProviderUnavailableException) rather than silently marking
            // everyone as eligible — so no activation can complete while
            // verification is disabled. Still not applied to the fake
            // driver, which is fixture-driven inside tests.
            if (! $verificationEnabled && $key !== 'fake') {
                return new DisabledVerificationDriver;
            }

            return match ($key) {
                'xtream' => (function () use ($app, $settings): XtreamVerificationDriver {
                    $dnsUrl = trim((string) $settings->value('provider.xtream_dns_url'));
                    $timeout = (int) ($settings->value('provider.timeout_seconds') ?? '8');
                    $statuses = array_values(array_filter(array_map(
                        'trim',
                        explode(',', (string) $settings->value('provider.active_status_values'))
                    )));
                    if ($statuses === []) {
                        $statuses = ['Active'];
                    }

                    // Note: dnsUrl may be empty at boot; the driver itself
                    // throws ProviderUnavailableException on verify calls
                    // so activation surfaces the standard temporarily-
                    // unavailable message instead of silently succeeding.
                    return new XtreamVerificationDriver(
                        http: $app->make(HttpFactory::class),
                        settings: $settings,
                        dnsUrl: $dnsUrl,
                        timeout: $timeout > 0 ? $timeout : 8,
                        activeStatusValues: $statuses,
                    );
                })(),

                'aio_iptv_v1' => (function () use ($app): AioIptvVerificationDriver {
                    $config = (array) config('provider.drivers.aio_iptv_v1', []);

                    return new AioIptvVerificationDriver(
                        http: $app->make(HttpFactory::class),
                        url: (string) ($config['url'] ?? ''),
                        apiKey: (string) ($config['api_key'] ?? ''),
                        timeout: (int) ($config['timeout'] ?? 8),
                    );
                })(),

                'fake' => new FakeVerificationDriver, // only reachable under APP_ENV=testing

                default => throw new InvalidArgumentException("Unknown provider verification driver [{$key}]."),
            };
        });
    }

    public function boot(): void
    {
        Event::listen(
            ReferralConversionApproved::class,
            EvaluateRewardsForApprovedConversion::class,
        );
        Event::listen(
            ReferralConversionApproved::class,
            EvaluateMilestoneUnlockForApprovedConversion::class,
        );
        Event::listen(
            NotificationSent::class,
            MarkMilestoneUnlockNotificationSent::class,
        );
    }
}
