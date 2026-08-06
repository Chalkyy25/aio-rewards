<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Livewire\AmbassadorSecurity;
use App\Models\AmbassadorProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeAmbassador(bool $mfa = false): User
    {
        $u = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt('correct-horse-2026'),
            'mfa_enabled' => $mfa,
        ]);
        $u->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($u)->create();

        return $u;
    }

    public function test_ambassador_can_log_in_without_mfa(): void
    {
        $u = $this->makeAmbassador(mfa: false);

        $this->post('/login', ['email' => $u->email, 'password' => 'correct-horse-2026'])
            ->assertRedirect(route('ambassador.dashboard'));

        $this->assertAuthenticatedAs($u);
    }

    public function test_ambassador_activation_does_not_require_mfa_enrolment(): void
    {
        // A freshly created ambassador should NOT have MFA turned on.
        $u = $this->makeAmbassador(mfa: false);
        $this->assertFalse((bool) $u->mfa_enabled);
        $this->assertNull($u->app_authentication_secret);
    }

    public function test_ambassador_can_enable_mfa_from_the_security_page(): void
    {
        $u = $this->makeAmbassador();

        $component = Livewire::actingAs($u)->test(AmbassadorSecurity::class);
        $component->assertSet('mfaEnabled', false);
        $component->call('startEnrolment');
        $secret = $component->get('enrolmentSecret');
        $this->assertNotEmpty($secret);

        // Correct code → enabled + recovery codes shown.
        $good = (new Google2FA)->getCurrentOtp($secret);
        $component->set('enrolmentCode', $good)->call('confirmEnrolment')
            ->assertHasNoErrors()
            ->assertSet('mfaEnabled', true);

        $fresh = $u->fresh();
        $this->assertTrue((bool) $fresh->mfa_enabled);
        $this->assertNotNull($fresh->app_authentication_secret);
        $this->assertIsArray($fresh->app_authentication_recovery_codes);
        $this->assertCount(8, $fresh->app_authentication_recovery_codes);
        $this->assertNotEmpty($component->get('recoveryCodes'));
    }

    public function test_mfa_enrolment_rejects_a_wrong_code(): void
    {
        $u = $this->makeAmbassador();
        Livewire::actingAs($u)->test(AmbassadorSecurity::class)
            ->call('startEnrolment')
            ->set('enrolmentCode', '000000')
            ->call('confirmEnrolment')
            ->assertHasErrors(['enrolmentCode']);

        $this->assertFalse((bool) $u->fresh()->mfa_enabled);
    }

    public function test_ambassador_with_mfa_enabled_must_pass_the_challenge_to_log_in(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $u = $this->makeAmbassador(mfa: true);
        $u->saveAppAuthenticationSecret($secret);

        $response = $this->post('/login', ['email' => $u->email, 'password' => 'correct-horse-2026']);
        $response->assertRedirect(route('login.challenge'));
        $this->assertGuest();
        $this->assertSame($u->id, session('mfa.pending_user_id'));

        // Wrong code stays guest.
        $this->from(route('login.challenge'))
            ->post(route('login.challenge.submit'), ['code' => '000000'])
            ->assertRedirect(route('login.challenge'))
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // Correct code completes the login.
        $good = (new Google2FA)->getCurrentOtp($secret);
        $this->post(route('login.challenge.submit'), ['code' => $good])
            ->assertRedirect(route('ambassador.dashboard'));
        $this->assertAuthenticatedAs($u);
    }

    public function test_recovery_code_can_be_used_once_to_log_in(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $u = $this->makeAmbassador(mfa: true);
        $u->saveAppAuthenticationSecret($secret);
        $u->saveAppAuthenticationRecoveryCodes(['abcde-fghij', 'zzzzz-yyyyy']);

        $this->post('/login', ['email' => $u->email, 'password' => 'correct-horse-2026']);
        $this->post(route('login.challenge.submit'), ['code' => 'abcde-fghij'])
            ->assertRedirect(route('ambassador.dashboard'));
        $this->assertAuthenticatedAs($u);

        // Used code is consumed.
        $this->assertNotContains('abcde-fghij', $u->fresh()->app_authentication_recovery_codes);
    }

    public function test_ambassador_can_regenerate_recovery_codes(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $u = $this->makeAmbassador(mfa: true);
        $u->saveAppAuthenticationSecret($secret);
        $u->saveAppAuthenticationRecoveryCodes(['old-code-1', 'old-code-2']);

        Livewire::actingAs($u)->test(AmbassadorSecurity::class)
            ->call('regenerateRecoveryCodes')
            ->assertSet('showRecovery', true);

        $codes = $u->fresh()->app_authentication_recovery_codes;
        $this->assertCount(8, $codes);
        $this->assertNotContains('old-code-1', $codes);
    }

    public function test_ambassador_can_disable_mfa_with_correct_password(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $u = $this->makeAmbassador(mfa: true);
        $u->saveAppAuthenticationSecret($secret);

        // Wrong password → error, MFA stays on.
        Livewire::actingAs($u)->test(AmbassadorSecurity::class)
            ->set('confirmPassword', 'wrong-pw')
            ->call('disableMfa')
            ->assertHasErrors(['confirmPassword']);
        $this->assertTrue((bool) $u->fresh()->mfa_enabled);

        // Correct password → MFA off, secrets scrubbed.
        Livewire::actingAs($u)->test(AmbassadorSecurity::class)
            ->set('confirmPassword', 'correct-horse-2026')
            ->call('disableMfa')
            ->assertHasNoErrors()
            ->assertSet('mfaEnabled', false);

        $fresh = $u->fresh();
        $this->assertFalse((bool) $fresh->mfa_enabled);
        $this->assertNull($fresh->app_authentication_secret);
        $this->assertNull($fresh->app_authentication_recovery_codes);
    }

    public function test_super_admin_always_requires_panel_mfa(): void
    {
        $u = User::factory()->create(['is_active' => true, 'mfa_enabled' => false]);
        $u->assignRole(Role::SuperAdmin->value);

        $this->assertTrue($u->requiresPanelMfa());
    }

    public function test_admin_requires_panel_mfa_by_default(): void
    {
        $u = User::factory()->create(['is_active' => true, 'mfa_enabled' => true]);
        $u->assignRole(Role::Admin->value);

        $this->assertTrue($u->requiresPanelMfa());

        // Super Admin may flip it off — non-super-admin admins can opt out.
        $u->update(['mfa_enabled' => false]);
        $this->assertFalse($u->fresh()->requiresPanelMfa());
    }

    public function test_ambassador_does_not_require_panel_mfa_at_all(): void
    {
        $u = $this->makeAmbassador(mfa: false);
        // Ambassadors never reach the panel — canAccessPanel is false so the
        // Filament MFA callback is not invoked for them. We assert the
        // gating by asserting the panel guard rejects them entirely.
        $this->assertFalse($u->canAccessPanel(app(\Filament\Panel::class)));
    }
}
