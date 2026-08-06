<?php

namespace Tests\Feature\Provider;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Enums\Role as RoleEnum;
use App\Filament\Pages\ProviderVerificationSettings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Locks in the fix for the "test connection reports 512 while activation
 * succeeds" bug. Guarantees the Test Connection modal and the activation
 * flow travel the identical container-bound driver, produce identical
 * diagnostics, and never persist / log / audit / expose the probe creds.
 */
class ProviderTestConnectionParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Point the container-bound driver at a deterministic host we can Http::fake().
        config(['provider.driver' => 'xtream']);
        settings()->putMany([
            'provider.enabled' => '1',
            'provider.xtream_dns_url' => 'https://iptv.example.test',
            'provider.timeout_seconds' => '8',
            'provider.active_status_values' => 'Active',
        ]);
        // Ensure the singleton picks up the new settings.
        app()->forgetInstance(CustomerVerificationContract::class);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['is_active' => true, 'mfa_enabled' => false, 'email_verified_at' => now()]);
        $u->assignRole(RoleEnum::SuperAdmin->value);

        return $u;
    }

    public function test_direct_contract_verify_succeeds_with_live_shaped_response(): void
    {
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response(['user_info' => ['auth' => 1, 'status' => 'Active', 'username' => 'alice']], 200),
        ]);

        $result = app(CustomerVerificationContract::class)
            ->verifyActiveCustomer(new VerifyCustomerRequest('alice', 'real-password'));

        $this->assertTrue($result->eligible);
        $this->assertSame('200', settings('provider.last_response_code'));
        $this->assertSame('eligible', settings('provider.last_note'));
        $this->assertNotNull(settings('provider.last_success_at'));
    }

    public function test_filament_test_connection_uses_the_same_binding_and_writes_matching_diagnostics(): void
    {
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response(['user_info' => ['auth' => 1, 'status' => 'Active', 'username' => 'alice']], 200),
        ]);

        Livewire::actingAs($this->superAdmin())
            ->test(ProviderVerificationSettings::class)
            ->callAction('testConnection', data: [
                'probe_username' => 'alice',
                'probe_password' => 'real-password',
            ])
            ->assertNotified();

        // The action must have taken the SAME code path as verifyActiveCustomer()
        // and written the SAME success diagnostics.
        $this->assertSame('200', settings('provider.last_response_code'));
        $this->assertSame('eligible', settings('provider.last_note'));
        $this->assertNotNull(settings('provider.last_success_at'));
    }

    public function test_both_paths_hit_the_same_endpoint_with_the_same_shape(): void
    {
        Http::fake([
            '*' => Http::response(['user_info' => ['auth' => 1, 'status' => 'Active']], 200),
        ]);

        // Path A: activation (direct contract call)
        app(CustomerVerificationContract::class)
            ->verifyActiveCustomer(new VerifyCustomerRequest('alice', 'real-password'));

        // Path B: Filament Test Connection modal
        Livewire::actingAs($this->superAdmin())
            ->test(ProviderVerificationSettings::class)
            ->callAction('testConnection', data: [
                'probe_username' => 'alice',
                'probe_password' => 'real-password',
            ]);

        $requests = collect(Http::recorded())->pluck(0);
        $this->assertCount(2, $requests, 'Expected exactly one request per verification path.');

        foreach ($requests as $req) {
            $this->assertStringContainsString('iptv.example.test/player_api.php', $req->url());
            $this->assertStringContainsString('username=alice', $req->url());
        }
    }

    public function test_probe_credentials_are_never_persisted_or_logged(): void
    {
        Log::spy();
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response(['user_info' => ['auth' => 1, 'status' => 'Active']], 200),
        ]);

        $secret = 'ephemeral-only-'.bin2hex(random_bytes(6));

        Livewire::actingAs($this->superAdmin())
            ->test(ProviderVerificationSettings::class)
            ->callAction('testConnection', data: [
                'probe_username' => 'alice',
                'probe_password' => $secret,
            ]);

        $this->assertFalse(
            Setting::query()->where('value', 'like', '%'.$secret.'%')->exists(),
            'Probe password must never be persisted to settings.'
        );
        $this->assertFalse(
            AuditLog::query()->where('context', 'like', '%'.$secret.'%')->exists(),
            'Probe password must never appear in the audit log.'
        );
        Log::shouldNotHaveReceived('warning', [$this->stringContains($secret)]);
        Log::shouldNotHaveReceived('info', [$this->stringContains($secret)]);
        Log::shouldNotHaveReceived('debug', [$this->stringContains($secret)]);
    }

    public function test_probe_credentials_are_not_persisted_to_livewire_public_state(): void
    {
        Http::fake([
            'iptv.example.test/player_api.php*' => Http::response(['user_info' => ['auth' => 1, 'status' => 'Active']], 200),
        ]);

        $secret = 'wire-state-guard-'.bin2hex(random_bytes(4));

        $component = Livewire::actingAs($this->superAdmin())
            ->test(ProviderVerificationSettings::class)
            ->callAction('testConnection', data: [
                'probe_username' => 'alice',
                'probe_password' => $secret,
            ]);

        // The Livewire snapshot after the action must not contain the secret
        // anywhere — not in $data, not in any bound property.
        $payload = json_encode($component->getData()) ?: '';
        $this->assertStringNotContainsString($secret, $payload, 'Probe password leaked into Livewire component state.');
    }

    public function test_upstream_failure_writes_failure_diagnostics_via_the_driver_not_the_page(): void
    {
        Http::fake(['iptv.example.test/player_api.php*' => Http::response('boom', 520)]);

        Livewire::actingAs($this->superAdmin())
            ->test(ProviderVerificationSettings::class)
            ->callAction('testConnection', data: [
                'probe_username' => 'alice',
                'probe_password' => 'pw',
            ])
            ->assertNotified();

        $this->assertSame('520', settings('provider.last_response_code'));
        $this->assertSame('upstream_error', settings('provider.last_note'));
        $this->assertNotNull(settings('provider.last_failure_at'));
    }
}
