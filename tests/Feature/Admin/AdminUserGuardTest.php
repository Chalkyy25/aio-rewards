<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminUserGuard;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $sa = User::factory()->create(['is_active' => true]);
        $sa->assignRole(RoleEnum::SuperAdmin->value);

        $this->expectException(DomainException::class);
        AdminUserGuard::syncRoles($sa, [RoleEnum::Admin->value]);
    }

    public function test_last_super_admin_cannot_be_deactivated(): void
    {
        $sa = User::factory()->create(['is_active' => true]);
        $sa->assignRole(RoleEnum::SuperAdmin->value);

        $this->expectException(DomainException::class);
        AdminUserGuard::setActive($sa, false);
    }

    public function test_last_super_admin_cannot_be_deleted(): void
    {
        $sa = User::factory()->create(['is_active' => true]);
        $sa->assignRole(RoleEnum::SuperAdmin->value);

        $this->expectException(DomainException::class);
        AdminUserGuard::delete($sa);
    }

    public function test_second_super_admin_may_be_demoted_or_deleted(): void
    {
        $sa1 = User::factory()->create(['is_active' => true]);
        $sa1->assignRole(RoleEnum::SuperAdmin->value);
        $sa2 = User::factory()->create(['is_active' => true]);
        $sa2->assignRole(RoleEnum::SuperAdmin->value);

        // With two SAs, demoting one is allowed.
        AdminUserGuard::syncRoles($sa1, [RoleEnum::Admin->value]);
        $this->assertFalse($sa1->fresh()->hasRole(RoleEnum::SuperAdmin->value));

        // sa2 is now the only SA — cannot delete it.
        $this->expectException(DomainException::class);
        AdminUserGuard::delete($sa2);
    }

    public function test_deactivated_super_admin_does_not_count_toward_minimum(): void
    {
        $sa1 = User::factory()->create(['is_active' => true]);
        $sa1->assignRole(RoleEnum::SuperAdmin->value);
        $sa2 = User::factory()->create(['is_active' => false]);
        $sa2->assignRole(RoleEnum::SuperAdmin->value);

        $this->expectException(DomainException::class);
        AdminUserGuard::setActive($sa1, false); // sa2 inactive → sa1 is last active
    }

    public function test_super_admin_only_can_reach_admin_users_resource(): void
    {
        $sa = User::factory()->create(['is_active' => true]);
        $sa->assignRole(RoleEnum::SuperAdmin->value);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RoleEnum::Admin->value);

        $this->actingAs($sa);
        $this->assertTrue(\App\Filament\Resources\UserResource::canAccess());

        $this->actingAs($admin);
        $this->assertFalse(\App\Filament\Resources\UserResource::canAccess());
    }
}
