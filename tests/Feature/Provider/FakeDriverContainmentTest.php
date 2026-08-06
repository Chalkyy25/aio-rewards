<?php

namespace Tests\Feature\Provider;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Domain\Provider\Drivers\XtreamVerificationDriver;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Enforces the "no fake driver in normal runtime environments" contract.
 *
 * The fake driver must be reachable ONLY when APP_ENV === "testing", and
 * even then only via an explicit $this->app->instance() bind — never from
 * config or .env.
 */
class FakeDriverContainmentTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function tearDown(): void
    {
        // Restore the testing environment for subsequent tests, since we
        // spoof it below.
        $this->app->detectEnvironment(fn () => 'testing');
        $this->app->forgetInstance(CustomerVerificationContract::class);
        parent::tearDown();
    }

    public function test_fake_driver_is_not_registered_in_config_drivers_map(): void
    {
        $drivers = config('provider.drivers');
        $this->assertIsArray($drivers);
        $this->assertArrayNotHasKey('fake', $drivers, 'The fake driver must not appear in config[provider.drivers].');
        $this->assertArrayHasKey('xtream', $drivers);
    }

    public function test_default_runtime_driver_is_xtream_not_fake(): void
    {
        $this->assertSame('xtream', config('provider.driver'));
    }

    public function test_fake_driver_cannot_be_resolved_in_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['provider.driver' => 'fake']);
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fake verification driver is not available outside the testing environment.');
        $this->app->make(CustomerVerificationContract::class);
    }

    public function test_fake_driver_cannot_be_resolved_in_staging_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['provider.driver' => 'fake']);
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(CustomerVerificationContract::class);
    }

    public function test_fake_driver_cannot_be_resolved_in_preview_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'preview');
        config(['provider.driver' => 'fake']);
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(CustomerVerificationContract::class);
    }

    public function test_fake_driver_cannot_be_resolved_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['provider.driver' => 'fake']);
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(CustomerVerificationContract::class);
    }

    public function test_xtream_driver_resolves_in_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['provider.driver' => 'xtream']);
        settings()->put('provider.xtream_dns_url', 'https://iptv.example.test');
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $driver = $this->app->make(CustomerVerificationContract::class);
        $this->assertInstanceOf(XtreamVerificationDriver::class, $driver);
    }

    public function test_fake_driver_is_reachable_under_testing_environment(): void
    {
        // Sanity check — the whole point of the test suite is that
        // tests CAN still exercise activation via the fake driver, but
        // only when they choose to.
        $this->app->detectEnvironment(fn () => 'testing');
        config(['provider.driver' => 'fake']);
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $driver = $this->app->make(CustomerVerificationContract::class);
        $this->assertInstanceOf(FakeVerificationDriver::class, $driver);
    }

    public function test_unknown_driver_key_throws(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['provider.driver' => 'not_a_real_driver']);
        $this->app->forgetInstance(CustomerVerificationContract::class);

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(CustomerVerificationContract::class);
    }
}
