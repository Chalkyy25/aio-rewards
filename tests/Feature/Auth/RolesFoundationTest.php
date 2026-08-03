<?php

namespace Tests\Feature\Auth;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_all_platform_roles_are_seeded(): void
    {
        foreach (RoleEnum::cases() as $role) {
            $this->assertDatabaseHas('roles', [
                'name' => $role->value,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_ambassador_role_cannot_access_filament_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RoleEnum::Ambassador->value);

        $panel = filament()->getPanel('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_admin_role_can_access_filament_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RoleEnum::Admin->value);

        $panel = filament()->getPanel('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_super_admin_role_can_access_filament_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RoleEnum::SuperAdmin->value);

        $panel = filament()->getPanel('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_inactive_admin_cannot_access_filament_panel(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(RoleEnum::Admin->value);

        $panel = filament()->getPanel('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }
}
