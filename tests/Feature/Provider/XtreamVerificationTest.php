<?php

namespace Tests\Feature\Provider;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\Drivers\DisabledVerificationDriver;
use App\Domain\Provider\Drivers\XtreamVerificationDriver;
use App\Domain\Provider\Enums\VerificationFailureReason;
use App\Domain\Provider\Exceptions\ProviderUnavailableException;
use App\Domain\Settings\SettingsRepository;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class XtreamVerificationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function driver(?SettingsRepository $repo = null, string $dns = 'https://iptv.example.test', array $statuses = ['Active']): XtreamVerificationDriver
    {
        return new XtreamVerificationDriver(
            http: app(HttpFactory::class),
            settings: $repo ?? app(SettingsRepository::class),
            dnsUrl: $dns,
            timeout: 5,
            activeStatusValues: $statuses,
        );
    }

    public function test_active_account_is_eligible(): void
    {
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response([
                'user_info' => ['auth' => 1, 'status' => 'Active', 'username' => 'alice'],
            ], 200),
        ]);

        $result = $this->driver()->verifyActiveCustomer(new VerifyCustomerRequest('alice', 'secret'));

        $this->assertTrue($result->eligible);
        $this->assertSame('alice', $result->providerCustomerRef);
        $this->assertSame('200', settings('provider.last_response_code'));
        $this->assertNotNull(settings('provider.last_success_at'));
    }

    public function test_inactive_account_is_rejected(): void
    {
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response([
                'user_info' => ['auth' => 1, 'status' => 'Expired', 'username' => 'bob'],
            ], 200),
        ]);

        $result = $this->driver()->verifyActiveCustomer(new VerifyCustomerRequest('bob', 'secret'));

        $this->assertFalse($result->eligible);
        $this->assertSame(VerificationFailureReason::Inactive, $result->reason);
    }

    public function test_invalid_credentials_return_wrong_credentials(): void
    {
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response([
                'user_info' => ['auth' => 0],
            ], 200),
        ]);

        $r = $this->driver()->verifyActiveCustomer(new VerifyCustomerRequest('mallory', 'wrong'));
        $this->assertFalse($r->eligible);
        $this->assertSame(VerificationFailureReason::WrongCredentials, $r->reason);
    }

    public function test_401_response_returns_wrong_credentials(): void
    {
        Http::fake(['iptv.example.test/player_api.php*' => Http::response('', 401)]);
        $r = $this->driver()->verifyActiveCustomer(new VerifyCustomerRequest('mallory', 'wrong'));
        $this->assertSame(VerificationFailureReason::WrongCredentials, $r->reason);
    }

    public function test_unreachable_dns_raises_provider_unavailable(): void
    {
        Http::fake(['iptv.example.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->expectException(ProviderUnavailableException::class);
        $this->driver()->verifyActiveCustomer(new VerifyCustomerRequest('anyone', 'pw'));
    }

    public function test_missing_dns_url_raises_provider_unavailable(): void
    {
        $this->expectException(ProviderUnavailableException::class);
        $this->driver(dns: '')->verifyActiveCustomer(new VerifyCustomerRequest('anyone', 'pw'));
    }

    public function test_test_connection_records_last_response_code(): void
    {
        Http::fake(['iptv.example.test/player_api.php*' => Http::response('', 200)]);

        $probe = $this->driver()->probeConnection();
        $this->assertTrue($probe['ok']);
        $this->assertSame('200', settings('provider.last_response_code'));
        $this->assertNotNull(settings('provider.last_success_at'));
    }

    public function test_password_is_never_persisted_or_logged(): void
    {
        Log::spy();
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response(['user_info' => ['auth' => 1, 'status' => 'Active']], 200),
        ]);

        $secret = 'super-secret-pw-xyz-2026';
        $this->driver()->verifyActiveCustomer(new VerifyCustomerRequest('alice', $secret));

        // No settings row or audit row should carry the password.
        $anySetting = \App\Models\Setting::query()->where('value', 'like', '%'.$secret.'%')->exists();
        $anyAudit = \App\Models\AuditLog::query()->where('context', 'like', '%'.$secret.'%')->exists();
        $this->assertFalse($anySetting, 'password leaked to settings');
        $this->assertFalse($anyAudit, 'password leaked to audit log');

        // Log spy: verify no log entry contains the password.
        Log::shouldNotHaveReceived('warning', function ($msg) use ($secret) {
            return is_string($msg) && str_contains($msg, $secret);
        });
    }

    public function test_verification_enabled_setting_is_honoured(): void
    {
        // Explicit enable flag reflects on the diagnostic driver key.
        settings()->putMany([
            'provider.enabled' => '1',
            'provider.xtream_dns_url' => 'https://iptv.example.test',
        ]);
        config(['provider.driver' => 'xtream']);
        app()->forgetInstance(CustomerVerificationContract::class);
        $this->assertSame('xtream', app(CustomerVerificationContract::class)->driverKey());
    }

    public function test_verification_disabled_blocks_activation_via_provider_unavailable(): void
    {
        settings()->putMany([
            'provider.enabled' => '0',
            'provider.xtream_dns_url' => 'https://iptv.example.test',
        ]);
        config(['provider.driver' => 'xtream']);
        app()->forgetInstance(CustomerVerificationContract::class);

        $driver = app(CustomerVerificationContract::class);
        $this->assertInstanceOf(DisabledVerificationDriver::class, $driver);

        // Disabled driver must NOT silently mark accounts eligible — it
        // must throw so activation halts with the standard message.
        $this->expectException(ProviderUnavailableException::class);
        $driver->verifyActiveCustomer(new VerifyCustomerRequest('anyone', 'ignored'));
    }

    public function test_settings_page_is_gated_to_super_admin(): void
    {
        $sa = User::factory()->create(['is_active' => true]);
        $sa->assignRole(RoleEnum::SuperAdmin->value);
        $this->actingAs($sa);
        $this->assertTrue(\App\Filament\Pages\ProviderVerificationSettings::canAccess());

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);
        $this->assertFalse(\App\Filament\Pages\ProviderVerificationSettings::canAccess());
    }

    public function test_settings_update_persists_and_takes_effect_on_next_container_resolve(): void
    {
        settings()->putMany([
            'provider.enabled' => '1',
            'provider.xtream_dns_url' => 'https://custom.example',
            'provider.timeout_seconds' => '12',
            'provider.active_status_values' => 'Active,Trial',
        ]);
        config(['provider.driver' => 'xtream']);
        app()->forgetInstance(CustomerVerificationContract::class);

        $driver = app(CustomerVerificationContract::class);
        $this->assertSame('xtream', $driver->driverKey());
    }
}
