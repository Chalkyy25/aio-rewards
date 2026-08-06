<?php

namespace Tests\Feature\Rewards;

use App\Models\AmbassadorProfile;
use App\Models\Reward;
use App\Models\RewardRule;
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
        RewardRule::query()->delete();
    }

    public function test_dashboard_renders_all_reward_stats(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $profile = AmbassadorProfile::factory()->for($user)->create();
        $rule = RewardRule::factory()->create(['trigger_count' => 5, 'amount_minor' => 5000]);

        // Build a rewards ledger: one pending, one approved, one paid.
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id, 'reward_rule_id' => $rule->id,
            'milestone_index' => 1, 'amount_minor' => 5000, 'status' => 'pending_approval',
        ]);
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id, 'reward_rule_id' => $rule->id,
            'milestone_index' => 2, 'amount_minor' => 5000, 'status' => 'approved',
        ]);
        Reward::factory()->create([
            'ambassador_profile_id' => $profile->id, 'reward_rule_id' => $rule->id,
            'milestone_index' => 3, 'amount_minor' => 5000, 'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSee('Pending reward')
            ->assertSee('Approved reward')
            ->assertSee('Paid reward')
            ->assertSee('Lifetime earned')
            ->assertSee('Next milestone')
            ->assertSeeText('£50.00')  // pending
            ->assertSeeText('£100.00'); // approved + paid
    }

    public function test_dashboard_shows_next_milestone_progress(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $profile = AmbassadorProfile::factory()->for($user)->create();
        RewardRule::factory()->create(['trigger_count' => 5, 'amount_minor' => 5000]);

        // Simulate 3 approved conversions.
        \App\Models\ReferralConversion::factory()->count(3)->create([
            'ambassador_profile_id' => $profile->id,
            'status' => 'approved',
        ]);

        $this->actingAs($user)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSeeText('3 / 5 referrals')
            ->assertSeeText('2 referrals')
            ->assertSeeText('until your next £50.00 reward.');
    }

    public function test_dashboard_gracefully_shows_dashes_when_no_rules_configured(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        AmbassadorProfile::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSee('No active reward rules yet.');
    }
}
