<?php

namespace App\Providers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\AioIptvVerificationDriver;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerVerificationContract::class, function (Application $app) {
            $key = (string) config('provider.driver');
            $config = config('provider.drivers.'.$key);

            if (! is_array($config) || ! isset($config['class'])) {
                throw new InvalidArgumentException("Unknown provider verification driver [{$key}].");
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
}
