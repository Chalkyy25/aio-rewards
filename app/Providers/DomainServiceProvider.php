<?php

namespace App\Providers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\AioIptvVerificationDriver;
use App\Domain\Provider\Drivers\DisabledVerificationDriver;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Domain\Provider\Drivers\XtreamVerificationDriver;
use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Domain\Settings\SettingsRepository;
use App\Listeners\EvaluateRewardsForApprovedConversion;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
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
            $config = config('provider.drivers.'.$key);

            if (! is_array($config) || ! isset($config['class'])) {
                throw new InvalidArgumentException("Unknown provider verification driver [{$key}].");
            }

            // Xtream driver is Settings-driven — swap in at runtime when the
            // Super Admin has switched to it via config (or, when the config
            // driver is not "fake", by presence of an Xtream DNS URL in
            // settings). Falls back to configured class otherwise.
            /** @var SettingsRepository $settings */
            $settings = $app->make(SettingsRepository::class);
            $verificationEnabled = (bool) (int) ($settings->value('provider.enabled') ?? '1');

            // When verification is toggled OFF in production, any driver may
            // route through the DisabledVerificationDriver — except in the
            // 'fake' driver preset (local/testing) where we keep the fixture
            // behaviour intact.
            if (! $verificationEnabled && $key !== 'fake') {
                return new DisabledVerificationDriver;
            }

            $dnsUrl = trim((string) $settings->value('provider.xtream_dns_url'));
            $useXtream = $key === 'xtream' || ($key !== 'fake' && $dnsUrl !== '');

            if ($useXtream) {
                $timeout = (int) ($settings->value('provider.timeout_seconds') ?? '8');
                $statuses = array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) $settings->value('provider.active_status_values'))
                )));
                if ($statuses === []) {
                    $statuses = ['Active'];
                }

                return new XtreamVerificationDriver(
                    http: $app->make(HttpFactory::class),
                    settings: $settings,
                    dnsUrl: $dnsUrl,
                    timeout: $timeout > 0 ? $timeout : 8,
                    activeStatusValues: $statuses,
                );
            }

            return match ($config['class']) {
                FakeVerificationDriver::class => new FakeVerificationDriver,
                AioIptvVerificationDriver::class => new AioIptvVerificationDriver(
                    http: $app->make(HttpFactory::class),
                    url: (string) ($config['url'] ?? ''),
                    apiKey: (string) ($config['api_key'] ?? ''),
                    timeout: (int) ($config['timeout'] ?? 8),
                ),
                default => throw new InvalidArgumentException("Unsupported driver class: {$config['class']}"),
            };
        });
    }

    public function boot(): void
    {
        Event::listen(
            ReferralConversionApproved::class,
            EvaluateRewardsForApprovedConversion::class,
        );
    }
}
