<?php

namespace Tests\Feature\Auth;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AmbassadorAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function ambassador(array $overrides = []): User
    {
        /** @var User $user */
        $user = User::factory()->create(array_merge([
            'email' => 'alice@example.com',
            'password' => Hash::make('correcthorsebattery'),
            'is_active' => true,
            'email_verified_at' => now(),
        ], $overrides));
        $user->assignRole(RoleEnum::Ambassador->value);

        return $user;
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_ambassador_can_login_and_reaches_dashboard(): void
    {
        $this->ambassador();

        $this->post('/login', [
            'email' => 'alice@example.com',
            'password' => 'correcthorsebattery',
        ])->assertRedirect(route('ambassador.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->ambassador();

        $this->from('/login')->post('/login', [
            'email' => 'alice@example.com',
            'password' => 'nope',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->ambassador(['is_active' => false]);

        $this->post('/login', [
            'email' => 'alice@example.com',
            'password' => 'correcthorsebattery',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_ends_session(): void
    {
        $user = $this->ambassador();
        $this->actingAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_verified_middleware_redirects_unverified_users(): void
    {
        $user = $this->ambassador(['email_verified_at' => null]);
        $this->actingAs($user);

        $this->get(route('ambassador.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_forgot_password_page_renders_and_accepts_email(): void
    {
        $this->ambassador();

        $this->get('/forgot-password')->assertOk();

        $this->post('/forgot-password', ['email' => 'alice@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status');
    }
}
