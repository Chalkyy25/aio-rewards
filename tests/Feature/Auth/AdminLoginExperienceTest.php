<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AmbassadorProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_public_login_shows_administrator_link(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Are you an administrator?')
            ->assertSee('href="/admin/login"', escape: false)
            ->assertSee('data-testid="login-admin-link"', escape: false);
    }

    public function test_ambassador_only_user_lands_on_ambassador_dashboard(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt('secret123'),
        ]);
        $user->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($user)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect(route('ambassador.dashboard'));
    }

    public function test_admin_only_user_lands_on_admin_panel(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt('secret123'),
        ]);
        $user->assignRole(Role::Admin->value);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect('/admin');
    }

    public function test_super_admin_only_user_lands_on_admin_panel(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt('secret123'),
        ]);
        $user->assignRole(Role::SuperAdmin->value);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect('/admin');
    }

    public function test_dual_role_user_lands_on_chooser_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => bcrypt('secret123'),
        ]);
        $user->assignRole(Role::Ambassador->value);
        $user->assignRole(Role::Admin->value);
        AmbassadorProfile::factory()->for($user)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect(route('post-login.choose'));

        $this->actingAs($user)
            ->get(route('post-login.choose'))
            ->assertOk()
            ->assertSee('Open Ambassador Dashboard')
            ->assertSee('Open Admin Panel')
            ->assertSee('data-testid="chooser-ambassador"', escape: false)
            ->assertSee('data-testid="chooser-admin"', escape: false);
    }

    public function test_chooser_redirects_single_role_users_home(): void
    {
        $ambassador = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $ambassador->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($ambassador)->create();

        $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $admin->assignRole(Role::Admin->value);

        $this->actingAs($ambassador)
            ->get(route('post-login.choose'))
            ->assertRedirect(route('ambassador.dashboard'));

        $this->actingAs($admin)
            ->get(route('post-login.choose'))
            ->assertRedirect('/admin');
    }

    public function test_ambassador_role_only_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($user)->create();

        // Filament returns 403 (or redirects to admin login) for unauthorised users.
        $response = $this->actingAs($user)->get('/admin');
        $this->assertContains($response->status(), [302, 403], 'expected admin panel to reject ambassador');
        if ($response->status() === 302) {
            $this->assertStringContainsString('/admin/login', (string) $response->headers->get('Location'));
        }
    }

    public function test_ambassador_dashboard_hides_admin_nav_for_ambassador_only(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('ambassador.dashboard'))
            ->assertOk()
            ->assertDontSee('data-testid="nav-admin-access"', escape: false)
            ->assertDontSee('Admin Access');
    }

    public function test_ambassador_dashboard_shows_admin_nav_for_dual_role_user(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole(Role::Ambassador->value);
        $user->assignRole(Role::Admin->value);
        AmbassadorProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('ambassador.dashboard'))
            ->assertOk()
            ->assertSee('Admin Access')
            ->assertSee('data-testid="nav-admin-access"', escape: false)
            ->assertSee('href="/admin"', escape: false);
    }
}
