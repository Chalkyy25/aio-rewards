<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneClaimUnavailableException;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralAllocation;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the extended ladder (5/10/15/20), excess-referral rollover,
 * cycle rollover on cash-out, maximum-tier behaviour, historical snapshot
 * immutability, and the dashboard's active-cycle vs lifetime split.
 */
class MilestoneLadderExtensionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->profile = AmbassadorProfile::factory()->for($this->user)->create(['flagged_for_review' => false]);
        MemberPayoutProfile::factory()->forProfile($this->profile)->accountCredit()->create();
    }

    private function approveConversions(int $n): void
    {
        $svc = app(ConversionService::class);
        for ($i = 1; $i <= $n; $i++) {
            $pkg = Package::factory()->create();
            $purchase = Purchase::factory()->create([
                'package_id' => $pkg->id,
                'status' => 'paid',
                'fulfilment_status' => 'completed',
                'paid_at' => now()->subDays(20),
                'ambassador_profile_id_snapshot' => $this->profile->id,
                'referral_code_snapshot' => $this->profile->referral_code,
            ]);
            $conv = ReferralConversion::create([
                'purchase_id' => $purchase->id,
                'ambassador_profile_id' => $this->profile->id,
                'referral_code_snapshot' => $this->profile->referral_code,
                'status' => 'pending',
                'amount_minor' => $purchase->amount_minor,
                'currency' => 'gbp',
                'pending_until' => now()->subDay(),
            ]);
            $svc->approve($conv);
        }
    }

    private function svc(): MilestoneProgressionService
    {
        return app(MilestoneProgressionService::class);
    }

    private function tier(int $threshold): RewardMilestoneTier
    {
        return RewardMilestoneTier::query()->where('threshold', $threshold)->firstOrFail();
    }

    // ---- Ladder shape ---------------------------------------------------

    public function test_seed_migration_configures_all_four_active_tiers(): void
    {
        $tiers = RewardMilestoneTier::query()->orderBy('threshold')->get();
        $this->assertSame([5, 10, 15, 20], $tiers->pluck('threshold')->all());
        $this->assertSame([5000, 11000, 17000, 23500], $tiers->pluck('total_reward_amount_minor')->all());
        $this->assertSame([0, 1000, 2000, 3500], $tiers->pluck('bonus_amount_minor')->all());
        $this->assertTrue($tiers->every(fn ($t) => $t->is_active && $t->is_visible && $t->is_claimable));
    }

    public function test_tier_seed_is_idempotent(): void
    {
        // Re-run just the ladder-extension migration; it must not duplicate.
        $migration = require database_path('migrations/2026_09_05_100000_extend_milestone_ladder_with_15_and_20.php');
        $migration->up();
        $migration->up();

        $this->assertSame(4, RewardMilestoneTier::query()->count());
        $this->assertSame(1, RewardMilestoneTier::query()->where('threshold', 15)->count());
        $this->assertSame(1, RewardMilestoneTier::query()->where('threshold', 20)->count());
    }

    // ---- Threshold unlocks ---------------------------------------------

    public function test_five_referrals_unlock_50(): void
    {
        $this->approveConversions(5);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(5000, $p->availableAmountMinor);
    }

    public function test_ten_referrals_unlock_110(): void
    {
        $this->approveConversions(10);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(11000, $p->availableAmountMinor);
    }

    public function test_fifteen_referrals_unlock_170(): void
    {
        $this->approveConversions(15);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(17000, $p->availableAmountMinor);
        $this->assertSame(15, $p->availableTier->threshold);
    }

    public function test_twenty_referrals_unlock_235_as_max(): void
    {
        $this->approveConversions(20);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(23500, $p->availableAmountMinor);
        $this->assertSame(20, $p->availableTier->threshold);
        $this->assertNull($p->nextTier, '20 is the maximum tier — no next tier');
    }

    // ---- Supersession ---------------------------------------------------

    public function test_lower_tiers_are_superseded_by_the_highest_unlocked(): void
    {
        // At 12, only £110 tier is claimable (not £50).
        $this->approveConversions(12);
        $this->expectException(MilestoneClaimUnavailableException::class);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
    }

    public function test_170_supersedes_110_and_50(): void
    {
        $this->approveConversions(15);
        foreach ([5, 10] as $th) {
            try {
                $this->svc()->claim($this->profile, $this->tier($th), $this->user, "k-$th");
                $this->fail("Tier {$th} should be superseded");
            } catch (MilestoneClaimUnavailableException) {
                $this->assertTrue(true);
            }
        }
        // But 15 is claimable.
        $r = $this->svc()->claim($this->profile, $this->tier(15), $this->user);
        $this->assertSame(17000, $r->amount_minor);
    }

    public function test_235_supersedes_all_lower_tiers(): void
    {
        $this->approveConversions(20);
        foreach ([5, 10, 15] as $th) {
            try {
                $this->svc()->claim($this->profile, $this->tier($th), $this->user, "k-$th");
                $this->fail("Tier {$th} should be superseded");
            } catch (MilestoneClaimUnavailableException) {
                $this->assertTrue(true);
            }
        }
        $r = $this->svc()->claim($this->profile, $this->tier(20), $this->user);
        $this->assertSame(23500, $r->amount_minor);
    }

    // ---- Cycle reset ----------------------------------------------------

    public function test_claim_at_exactly_5_resets_active_cycle_to_zero(): void
    {
        $this->approveConversions(5);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount);
        $this->assertSame(5, $p->nextTier->threshold);
    }

    public function test_claim_at_exactly_20_resets_active_cycle_to_zero(): void
    {
        $this->approveConversions(20);
        $this->svc()->claim($this->profile, $this->tier(20), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount);
        $this->assertSame(5, $p->nextTier->threshold, 'Ladder restarts from tier 1');
    }

    // ---- Excess-referral rollover --------------------------------------

    public function test_seven_referrals_then_50_claim_rolls_two_into_next_cycle(): void
    {
        $this->approveConversions(7);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(2, $p->eligibleCount, '2 excess referrals roll over');
        $this->assertSame(3, $p->referralsRemaining, '3 more to unlock £50 again');
    }

    public function test_twelve_referrals_then_110_claim_rolls_two_into_next_cycle(): void
    {
        $this->approveConversions(12);
        $this->svc()->claim($this->profile, $this->tier(10), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(2, $p->eligibleCount);
    }

    public function test_seventeen_referrals_then_170_claim_rolls_two_into_next_cycle(): void
    {
        $this->approveConversions(17);
        $this->svc()->claim($this->profile, $this->tier(15), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(2, $p->eligibleCount);
    }

    public function test_twentythree_referrals_then_235_claim_rolls_three_into_next_cycle(): void
    {
        $this->approveConversions(23);
        $this->svc()->claim($this->profile, $this->tier(20), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(3, $p->eligibleCount);
    }

    public function test_rolled_referrals_contribute_toward_next_50_milestone(): void
    {
        $this->approveConversions(7);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        // 2 rolled — add 3 more.
        $this->approveConversions(3);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(5, $p->eligibleCount);
        $this->assertSame(5000, $p->availableAmountMinor);
    }

    // ---- Deterministic FIFO --------------------------------------------

    public function test_oldest_eligible_referrals_are_consumed_first(): void
    {
        $this->approveConversions(6);
        $oldest = ReferralConversion::query()
            ->where('ambassador_profile_id', $this->profile->id)
            ->orderBy('approved_at')->orderBy('id')
            ->limit(5)->pluck('id')->all();

        $reward = $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $allocated = ReferralAllocation::query()
            ->where('reward_id', $reward->id)
            ->pluck('referral_conversion_id')->all();
        sort($oldest);
        sort($allocated);
        $this->assertSame($oldest, $allocated);
    }

    // ---- Lifetime vs cycle ---------------------------------------------

    public function test_lifetime_totals_survive_multiple_cycles(): void
    {
        // 5 + claim + 5 + claim + 3 = 13 approved conversions total.
        $this->approveConversions(5);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $this->approveConversions(5);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $this->approveConversions(3);

        $lifetime = ReferralConversion::query()
            ->where('ambassador_profile_id', $this->profile->id)
            ->where('status', 'approved')
            ->count();
        $this->assertSame(13, $lifetime, 'Lifetime approved count never resets');

        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(3, $p->eligibleCount, 'Active cycle only sees the current 3');
        $this->assertSame(3, $this->svc()->currentCycleNumber($this->profile->id));
    }

    public function test_full_ladder_cycle_can_repeat_indefinitely(): void
    {
        // Claim £235 twice, then start climbing again.
        $this->approveConversions(20);
        $r1 = $this->svc()->claim($this->profile, $this->tier(20), $this->user);
        $this->approveConversions(20);
        $r2 = $this->svc()->claim($this->profile, $this->tier(20), $this->user);
        $this->assertNotSame($r1->id, $r2->id);
        $this->assertSame(23500, $r1->amount_minor);
        $this->assertSame(23500, $r2->amount_minor);
        $this->assertSame(1, $r1->cycle_number);
        $this->assertSame(2, $r2->cycle_number);
    }

    // ---- Snapshot immutability -----------------------------------------

    public function test_historical_reward_snapshots_survive_tier_edits(): void
    {
        $this->approveConversions(15);
        $reward = $this->svc()->claim($this->profile, $this->tier(15), $this->user);
        $this->assertSame(17000, $reward->amount_minor);

        // Admin changes tier 15's amount later.
        $this->tier(15)->update([
            'total_reward_amount_minor' => 99900,
            'title' => 'Something different',
        ]);

        $fresh = $reward->fresh();
        $this->assertSame(17000, $fresh->amount_minor, 'Reward amount is snapshotted, not recomputed');
        $this->assertSame(17000, $fresh->tier_snapshot['total_reward_amount_minor']);
        $this->assertSame('£170 Reward', $fresh->tier_snapshot['title']);
    }

    // ---- UI: max-tier and dashboard active-cycle -----------------------

    public function test_milestones_page_renders_all_four_tiers(): void
    {
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        foreach ([5, 10, 15, 20] as $th) {
            $res->assertSee("data-testid=\"tier-card-{$th}\"", false);
        }
        $res->assertSee('data-testid="tier-20-max-badge"', false);
    }

    public function test_milestones_page_shows_max_tier_state_at_20(): void
    {
        $this->approveConversions(20);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSee('data-testid="claim-cta-20"', false);
        $res->assertSeeText('Maximum reward unlocked');
        // No lower-tier claim CTA remains available.
        $res->assertDontSee('data-testid="claim-cta-5"', false);
        $res->assertDontSee('data-testid="claim-cta-10"', false);
        $res->assertDontSee('data-testid="claim-cta-15"', false);
        // No "next reward at 25" text.
        $res->assertDontSeeText('25 referrals');
        $res->assertSee('data-testid="tier-card-max-cycle-note"', false);
    }

    public function test_milestones_page_shows_building_toward_170_between_10_and_15(): void
    {
        $this->approveConversions(12);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSee('data-testid="claim-cta-10"', false);
        $res->assertSeeText('12 / 15 referrals');
        $res->assertSeeText('3 to go');
    }

    public function test_milestones_page_shows_building_toward_235_between_15_and_20(): void
    {
        $this->approveConversions(17);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSee('data-testid="claim-cta-15"', false);
        $res->assertSeeText('17 / 20 referrals');
    }

    public function test_dashboard_shows_active_cycle_progress_not_lifetime(): void
    {
        // Lifetime = 12, but after claiming £110, cycle progress must be 2/5.
        $this->approveConversions(12);
        $this->svc()->claim($this->profile, $this->tier(10), $this->user);

        $res = $this->actingAs($this->user)->get('/ambassador/dashboard')->assertOk();
        $res->assertSeeText('2 / 5 approved referrals'); // active cycle
        $res->assertSeeText('12'); // lifetime count is displayed separately
        $res->assertSee('data-testid="stat-lifetime-approved"', false);
    }

    public function test_dashboard_shows_max_tier_message_at_20(): void
    {
        $this->approveConversions(20);
        $res = $this->actingAs($this->user)->get('/ambassador/dashboard')->assertOk();
        $res->assertSeeText('Maximum reward unlocked');
    }
}
