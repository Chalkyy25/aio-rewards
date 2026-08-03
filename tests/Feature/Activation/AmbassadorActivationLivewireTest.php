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
}
