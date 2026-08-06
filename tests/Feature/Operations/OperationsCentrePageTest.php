<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\OperationsSpec;
use App\Domain\Operations\OperationsWriter;
use App\Enums\OperationsType;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsCentrePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_can_load_operations_index_page(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'mfa_enabled' => false, 'email_verified_at' => now()]);
        $admin->assignRole(RoleEnum::SuperAdmin->value);

        $this->actingAs($admin)
            ->get('/admin/operations')
            ->assertOk()
            ->assertSee('Operations Centre');
    }

    public function test_ambassador_cannot_reach_operations_index(): void
    {
        $u = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole(RoleEnum::Ambassador->value);

        $this->actingAs($u)
            ->get('/admin/operations')
            ->assertStatus(403);
    }

    public function test_viewing_an_item_records_first_view(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'mfa_enabled' => false, 'email_verified_at' => now()]);
        $admin->assignRole(RoleEnum::SuperAdmin->value);

        $item = app(OperationsWriter::class)->upsert(new OperationsSpec(
            type: OperationsType::OrderPaidUnviewed,
            dedupeKey: 'view-test:1',
            title: 'Test item to view',
        ));

        $this->assertNull($item->first_viewed_at);

        $this->actingAs($admin)->get('/admin/operations/'.$item->id)->assertOk();

        $item->refresh();
        $this->assertNotNull($item->first_viewed_at);
        $this->assertSame($admin->id, $item->first_viewed_by_user_id);
    }

    public function test_home_url_redirects_to_operations_centre(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::SuperAdmin->value);

        // Filament's homeUrl closure returns the Operations Centre URL.
        $panel = filament()->getPanel('admin');
        $home = $panel->getHomeUrl();
        $this->assertStringContainsString('/admin/operations', $home);
    }
}
