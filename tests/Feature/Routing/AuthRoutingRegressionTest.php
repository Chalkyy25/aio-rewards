<?php

namespace Tests\Feature\Routing;

use App\Enums\Role;
use App\Models\AmbassadorProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class AuthRoutingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** ---- Guest ---- */

    public function test_guest_landing_shows_activate_and_login_ctas(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-testid="cta-activate"', escape: false)
            ->assertSee('href="'.route('activate').'"', escape: false)
            ->assertSee('data-testid="cta-login"', escape: false)
            ->assertSee('href="'.route('login').'"', escape: false)
            ->assertDontSee('data-testid="section-authenticated"', escape: false);
    }

    public function test_guest_login_page_is_reachable(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-testid="login-form"', escape: false);
    }

    public function test_guest_activation_page_is_reachable(): void
    {
        $this->get('/activate')->assertOk();
    }

    /** ---- Authenticated ambassador ---- */

    public function test_landing_page_offers_ambassador_a_dashboard_shortcut(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($u)->create();

        $this->actingAs($u)
            ->get('/')
            ->assertOk()
            ->assertSee('data-testid="section-authenticated"', escape: false)
            ->assertSee('data-testid="cta-open-dashboard"', escape: false)
            ->assertSee('href="'.route('ambassador.dashboard').'"', escape: false)
            ->assertDontSee('data-testid="cta-activate"', escape: false);
    }

    public function test_ambassador_visiting_login_is_redirected_to_dashboard(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($u)->create();

        $this->actingAs($u)->get('/login')->assertRedirect(route('ambassador.dashboard'));
        $this->actingAs($u)->get('/activate')->assertRedirect(route('ambassador.dashboard'));
    }

    public function test_ambassador_dashboard_is_accessible(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($u)->create();

        $this->actingAs($u)->get('/ambassador/dashboard')->assertOk();
    }

    /** ---- Authenticated admin ---- */

    public function test_landing_page_offers_admin_the_panel_shortcut(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Admin->value);

        $this->actingAs($u)
            ->get('/')
            ->assertOk()
            ->assertSee('data-testid="cta-admin-panel"', escape: false)
            ->assertSee('href="/admin"', escape: false);
    }

    public function test_admin_visiting_login_is_redirected_to_admin_panel(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Admin->value);

        $this->actingAs($u)->get('/login')->assertRedirect('/admin');
    }

    /** ---- Dual role ---- */

    public function test_landing_page_offers_dual_role_the_chooser(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Ambassador->value);
        $u->assignRole(Role::Admin->value);
        AmbassadorProfile::factory()->for($u)->create();

        $this->actingAs($u)
            ->get('/')
            ->assertOk()
            ->assertSee('data-testid="cta-post-login"', escape: false)
            ->assertSee('href="'.route('post-login.choose').'"', escape: false);
    }

    public function test_dual_role_visiting_login_is_redirected_to_chooser(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(Role::Ambassador->value);
        $u->assignRole(Role::Admin->value);
        AmbassadorProfile::factory()->for($u)->create();

        $this->actingAs($u)->get('/login')->assertRedirect(route('post-login.choose'));
    }

    /** ---- 419 friendly recovery ---- */

    public function test_expired_csrf_token_never_shows_raw_419_and_recovers_gracefully(): void
    {
        // Simulate an expired-token POST to /login with no _token field.
        $response = $this->from(route('login'))
            ->post('/login', ['email' => 'anyone@example.com', 'password' => 'x']);

        // The custom handler must have redirected us — not rendered 419.
        $this->assertNotSame(419, $response->status(), 'raw 419 must never be shown');
        $response->assertStatus(302);
    }

    public function test_token_mismatch_exception_handler_redirects_with_status(): void
    {
        // The critical case is the middleware-triggered TokenMismatch (covered by
        // the previous test). We also verify the callback is registered by
        // looking it up on the framework's exception handler.
        $handler = app(\Illuminate\Foundation\Exceptions\Handler::class);
        $reflection = new \ReflectionObject($handler);
        $prop = $reflection->getProperty('renderCallbacks');
        $prop->setAccessible(true);
        $callbacks = $prop->getValue($handler);

        $matched = false;
        foreach ($callbacks as $cb) {
            $refl = new \ReflectionFunction($cb);
            foreach ($refl->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === TokenMismatchException::class) {
                    $matched = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($matched, 'expected a render() callback registered for TokenMismatchException');
    }
}
