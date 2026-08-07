<?php

namespace Tests\Feature\Rewards;

use App\Filament\Resources\RewardMilestoneTierResource;
use App\Filament\Resources\RewardMilestoneTierResource\Pages\CreateRewardMilestoneTier;
use App\Filament\Resources\RewardMilestoneTierResource\Pages\ListRewardMilestoneTiers;
use App\Models\AmbassadorProfile;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMilestoneTierManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole(\App\Enums\Role::SuperAdmin->value);
        Filament::setCurrentPanel('admin');
    }

    public function test_admin_can_list_tiers(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListRewardMilestoneTiers::class)
            ->assertOk()
            ->assertCanSeeTableRecords(RewardMilestoneTier::all());
    }

    public function test_admin_can_create_a_new_tier(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateRewardMilestoneTier::class)
            ->fillForm([
                'title' => '£250 Reward',
                'threshold' => 25,
                'total_reward_amount_minor' => 25000,
                'bonus_amount_minor' => 5000,
                'currency' => 'gbp',
                'display_order' => 30,
                'is_active' => true,
                'is_visible' => true,
                'is_claimable' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('reward_milestone_tiers', [
            'threshold' => 25,
            'total_reward_amount_minor' => 25000,
        ]);
    }

    public function test_admin_cannot_create_duplicate_active_threshold(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateRewardMilestoneTier::class)
            ->fillForm([
                'title' => 'Dup 5',
                'threshold' => 5,  // already seeded and active
                'total_reward_amount_minor' => 9999,
                'currency' => 'gbp',
                'is_active' => true,
                'is_visible' => true,
                'is_claimable' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['threshold']);
    }

    public function test_regular_member_cannot_reach_admin_tier_pages(): void
    {
        $u = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        AmbassadorProfile::factory()->for($u)->create();
        $this->actingAs($u)->get(RewardMilestoneTierResource::getUrl())->assertStatus(403);
    }
}
