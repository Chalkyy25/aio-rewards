<?php

namespace Tests\Feature\Referrals;

use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\ReferralClick;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralClickAdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ambassador_cannot_access_filament_referral_clicks_resource(): void
    {
        $ambassador = User::factory()->create(['is_active' => true]);
        $ambassador->assignRole(RoleEnum::Ambassador->value);

        $this->actingAs($ambassador)
            ->get('/admin/referral-clicks')
            ->assertStatus(403);
    }

    public function test_admin_can_view_filament_referral_clicks_resource(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RoleEnum::Admin->value);
        $profile = AmbassadorProfile::factory()->create();
        ReferralClick::factory()->count(2)->create(['ambassador_profile_id' => $profile->id]);

        $response = $this->actingAs($admin)->get('/admin/referral-clicks');
        // Either 200 (MFA already enrolled) or a redirect to MFA setup — both prove
        // the policy allowed access (a policy denial would be 403).
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_referral_click_policy_denies_mutation(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RoleEnum::SuperAdmin->value);
        $click = ReferralClick::factory()->create();

        $this->assertFalse($admin->can('create', ReferralClick::class));
        $this->assertFalse($admin->can('update', $click));
        $this->assertFalse($admin->can('delete', $click));
        $this->assertTrue($admin->can('view', $click));
    }
}
