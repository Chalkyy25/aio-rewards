<?php

namespace Tests\Feature\Rewards;

use App\Models\AmbassadorProfile;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmbassadorRewardsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dashboard_renders_all_reward_stats(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $profile = AmbassadorProfile::factory()->for($user)->create();
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->first();

        // Rewards ledger: one pending, one approved, one paid.
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id, 'milestone_tier_id' => $tier->id,
            'milestone_index' => 1, 'amount_minor' => 5000, 'status' => 'pending_approval',
        ]);
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id, 'milestone_tier_id' => $tier->id,
            'milestone_index' => 2, 'amount_minor' => 5000, 'status' => 'approved',
        ]);
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id, 'milestone_tier_id' => $tier->id,
            'milestone_index' => 3, 'amount_minor' => 5000, 'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSee('Available now')
            ->assertSee('Pending reward')
            ->assertSee('Approved reward')
            ->assertSee('Paid reward')
            ->assertSee('Lifetime earned')
            ->assertSee('Next reward');
    }

    public function test_dashboard_shows_next_reward_progress(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $profile = AmbassadorProfile::factory()->for($user)->create();

        // Simulate 3 approved conversions (below tier 1's threshold of 5).
        ReferralConversion::factory()->count(3)->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
        ]);

        $this->actingAs($user)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSeeText('3 / 5 approved referrals')
            ->assertSee('View Reward Milestones');
    }

    public function test_dashboard_gracefully_shows_dashes_when_no_tiers_configured(): void
    {
        RewardMilestoneTier::query()->update(['is_active' => false]);
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        AmbassadorProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSee('No active reward tiers yet.');
    }
}
