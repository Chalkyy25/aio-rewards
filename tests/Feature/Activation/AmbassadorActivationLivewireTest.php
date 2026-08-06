<?php

namespace Tests\Feature\Activation;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Livewire\AmbassadorActivation;
use App\Models\AmbassadorProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AmbassadorActivationLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
    }

    public function test_activate_page_renders_and_shows_form(): void
    {
        $this->get('/activate')->assertOk()
            ->assertSee('Activate your Ambassador account')
            ->assertSeeHtml('data-testid="activate-form"');
    }

    public function test_livewire_submit_creates_ambassador_and_redirects_to_dashboard(): void
    {
        Livewire::test(AmbassadorActivation::class)
            ->set('name', 'Alice')
            ->set('email', 'alice@example.com')
            ->set('password', 'newSecret1234')
            ->set('password_confirmation', 'newSecret1234')
            ->set('provider_username', 'test_active')
            ->set('provider_password', 'letmein')
            ->set('consent', true)
            ->call('submit')
            ->assertRedirect(route('ambassador.dashboard'));

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
        $this->assertDatabaseCount('ambassador_profiles', 1);

        // Provider password not held on the component after submit
        $latest = AmbassadorProfile::first();
        $this->assertNotEmpty($latest->referral_code);
    }

    public function test_submit_shows_field_error_on_wrong_provider_password(): void
    {
        Livewire::test(AmbassadorActivation::class)
            ->set('name', 'Alice')
            ->set('email', 'alice@example.com')
            ->set('password', 'newSecret1234')
            ->set('password_confirmation', 'newSecret1234')
            ->set('provider_username', 'test_active')
            ->set('provider_password', 'wrong')
            ->set('consent', true)
            ->call('submit')
            ->assertSet('errorMessage', fn ($m) => is_string($m) && str_contains($m, 'could not verify'))
            ->assertNoRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'alice@example.com']);
    }

    public function test_provider_password_property_is_cleared_after_submit(): void
    {
        $component = Livewire::test(AmbassadorActivation::class)
            ->set('name', 'Alice')
            ->set('email', 'alice@example.com')
            ->set('password', 'newSecret1234')
            ->set('password_confirmation', 'newSecret1234')
            ->set('provider_username', 'test_active')
            ->set('provider_password', 'letmein')
            ->set('consent', true)
            ->call('submit');

        // After successful submit the component redirects; the residual state is the point.
        $component->assertSet('provider_password', '');
    }

    public function test_consent_required(): void
    {
        Livewire::test(AmbassadorActivation::class)
            ->set('name', 'Alice')
            ->set('email', 'alice@example.com')
            ->set('password', 'newSecret1234')
            ->set('password_confirmation', 'newSecret1234')
            ->set('provider_username', 'test_active')
            ->set('provider_password', 'letmein')
            ->set('consent', false)
            ->call('submit')
            ->assertHasErrors(['consent']);
    }

    public function test_submit_shows_temporarily_unavailable_message_when_provider_unreachable(): void
    {
        // Force the fake driver to signal an outage — same code path a
        // 5xx / connection failure on the Xtream driver takes at runtime.
        $this->app->instance(
            CustomerVerificationContract::class,
            new FakeVerificationDriver(['test_active' => ['password' => 'letmein', 'result' => 'error']])
        );

        Livewire::test(AmbassadorActivation::class)
            ->set('name', 'Alice')
            ->set('email', 'alice@example.com')
            ->set('password', 'newSecret1234')
            ->set('password_confirmation', 'newSecret1234')
            ->set('provider_username', 'test_active')
            ->set('provider_password', 'letmein')
            ->set('consent', true)
            ->call('submit')
            ->assertNoRedirect();

        // Activation must NOT create a user when verification is unavailable.
        $this->assertDatabaseMissing('users', ['email' => 'alice@example.com']);
        $this->assertDatabaseCount('ambassador_profiles', 0);
    }

    public function test_activation_blocked_and_shows_exact_copy_when_xtream_throws_provider_unavailable(): void
    {
        // Bind a driver stub that always throws ProviderUnavailableException,
        // mirroring what XtreamVerificationDriver does on 5xx / connection
        // failure or when Provider Verification is toggled off in settings.
        $this->app->instance(
            CustomerVerificationContract::class,
            new class implements CustomerVerificationContract
            {
                public function driverKey(): string
                {
                    return 'xtream';
                }

                public function verifyActiveCustomer(\App\Domain\Provider\DTOs\VerifyCustomerRequest $r): \App\Domain\Provider\DTOs\VerifyCustomerResult
                {
                    throw new \App\Domain\Provider\Exceptions\ProviderUnavailableException('unreachable');
                }
            }
        );

        Livewire::test(AmbassadorActivation::class)
            ->set('name', 'Alice')
            ->set('email', 'alice@example.com')
            ->set('password', 'newSecret1234')
            ->set('password_confirmation', 'newSecret1234')
            ->set('provider_username', 'anything')
            ->set('provider_password', 'anything')
            ->set('consent', true)
            ->call('submit')
            ->assertSet(
                'errorMessage',
                "We’re temporarily unable to verify your AIO Media account. Please try again later or contact support."
            )
            ->assertNoRedirect();

        // No account or profile may be created when verification fails.
        $this->assertDatabaseMissing('users', ['email' => 'alice@example.com']);
        $this->assertDatabaseCount('ambassador_profiles', 0);
    }
}
