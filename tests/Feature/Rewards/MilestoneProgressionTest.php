<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneClaimUnavailableException;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralAllocation;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\RewardRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneProgressionTest extends TestCase
{
    use RefreshDatabase;

    private AmbassadorProfile $profile;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->profile = AmbassadorProfile::factory()->for($this->user)->create(['flagged_for_review' => false]);
        // The migration seeds tier 1 (5 → £50) and tier 2 (10 → £110).
    }

    /** Approve $n conversions using ConversionService (uses the full pipeline). */
    private function approveConversions(int $n): void
    {
        $svc = app(ConversionService::class);
        for ($i = 1; $i <= $n; $i++) {
            $package = Package::factory()->create();
            $purchase = Purchase::factory()->create([
                'package_id' => $package->id,
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

    public function test_zero_to_four_approved_yields_nothing_claimable(): void
    {
        $this->approveConversions(4);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertNull($p->availableTier);
        $this->assertSame(4, $p->eligibleCount);
    }

    public function test_exactly_five_makes_50_pounds_available(): void
    {
        $this->approveConversions(5);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertNotNull($p->availableTier);
        $this->assertSame(5, $p->availableTier->threshold);
        $this->assertSame(5000, $p->availableAmountMinor);
        $this->assertSame(0, Reward::count(), 'No reward is minted before member claims');
    }

    public function test_member_can_claim_50_pounds_at_5(): void
    {
        $this->approveConversions(5);
        $reward = $this->svc()->claim($this->profile, $this->tier(5), $this->user);

        $this->assertSame('pending_approval', $reward->status);
        $this->assertSame(5000, $reward->amount_minor);
        $this->assertSame('milestone_claim', $reward->origin);
        $this->assertSame(1, $reward->cycle_number);
        $this->assertNotNull($reward->tier_snapshot);
        $this->assertSame(5, ReferralAllocation::query()->where('reward_id', $reward->id)->count());
    }

    public function test_50_pound_claim_consumes_those_five_referrals(): void
    {
        $this->approveConversions(5);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount);
        $this->assertNull($p->availableTier);
    }

    public function test_next_five_after_claim_create_another_50_not_110(): void
    {
        $this->approveConversions(5);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $this->approveConversions(5); // another five referrals in the new cycle

        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(5, $p->eligibleCount);
        $this->assertSame(5, $p->availableTier->threshold);
        $this->assertSame(5000, $p->availableAmountMinor, 'Second cycle offers £50, not £110');
        $this->assertSame(2, $this->svc()->currentCycleNumber($this->profile->id));
    }

    public function test_member_who_does_not_claim_at_5_progresses_to_10(): void
    {
        $this->approveConversions(5);
        // Do not claim.
        $this->approveConversions(4); // 6..9
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(9, $p->eligibleCount);
        $this->assertSame(5, $p->availableTier->threshold, '£50 still available while building');
        $this->assertNotNull($p->nextTier);
        $this->assertSame(10, $p->nextTier->threshold);
        $this->assertSame(1, $p->referralsRemaining);
    }

    public function test_exactly_ten_makes_110_pounds_available(): void
    {
        $this->approveConversions(10);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(10, $p->availableTier->threshold);
        $this->assertSame(11000, $p->availableAmountMinor);
    }

    public function test_110_pound_claim_creates_one_reward(): void
    {
        $this->approveConversions(10);
        $reward = $this->svc()->claim($this->profile, $this->tier(10), $this->user);
        $this->assertSame(1, Reward::count());
        $this->assertSame(11000, $reward->amount_minor);
    }

    public function test_110_pound_claim_consumes_all_ten_referrals(): void
    {
        $this->approveConversions(10);
        $reward = $this->svc()->claim($this->profile, $this->tier(10), $this->user);
        $this->assertSame(10, ReferralAllocation::query()->where('reward_id', $reward->id)->count());
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount);
    }

    public function test_next_referral_after_110_claim_starts_new_cycle(): void
    {
        $this->approveConversions(10);
        $this->svc()->claim($this->profile, $this->tier(10), $this->user);
        $this->approveConversions(1);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(1, $p->eligibleCount);
        $this->assertSame(2, $this->svc()->currentCycleNumber($this->profile->id));
    }

    public function test_claim_at_5_is_blocked_when_10_is_reached(): void
    {
        $this->approveConversions(10);
        $this->expectException(MilestoneClaimUnavailableException::class);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
    }

    public function test_double_claim_is_idempotent_via_key(): void
    {
        $this->approveConversions(5);
        $r1 = $this->svc()->claim($this->profile, $this->tier(5), $this->user, 'client-key-1');
        $r2 = $this->svc()->claim($this->profile, $this->tier(5), $this->user, 'client-key-1');
        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(1, Reward::count());
    }

    public function test_second_claim_without_idempotency_is_blocked(): void
    {
        $this->approveConversions(5);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $this->expectException(MilestoneClaimUnavailableException::class);
        $this->svc()->claim($this->profile, $this->tier(5), $this->user);
    }

    public function test_pending_conversions_do_not_count(): void
    {
        // Create raw pending conversions bypassing the ConversionService (no approval).
        for ($i = 0; $i < 6; $i++) {
            $pkg = Package::factory()->create();
            $purchase = Purchase::factory()->create(['package_id' => $pkg->id, 'status' => 'paid']);
            ReferralConversion::create([
                'purchase_id' => $purchase->id,
                'ambassador_profile_id' => $this->profile->id,
                'referral_code_snapshot' => $this->profile->referral_code,
                'status' => 'pending',
                'amount_minor' => $purchase->amount_minor,
                'currency' => 'gbp',
                'pending_until' => now()->addDay(),
            ]);
        }
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount);
    }

    public function test_reversed_conversions_do_not_count(): void
    {
        $this->approveConversions(5);
        ReferralConversion::query()->update(['status' => 'reversed', 'reversed_at' => now()]);
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount);
    }

    public function test_conversion_cannot_have_two_active_allocations(): void
    {
        $this->approveConversions(5);
        $r = $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $conv = ReferralConversion::query()->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        ReferralAllocation::create([
            'referral_conversion_id' => $conv->id,
            'ambassador_profile_id' => $this->profile->id,
            'cycle_number' => 1,
            'reward_id' => $r->id,
            'active_marker' => 1,
            'allocated_at' => now(),
        ]);
    }

    public function test_reject_and_release_restores_eligibility(): void
    {
        $this->approveConversions(5);
        $r = $this->svc()->claim($this->profile, $this->tier(5), $this->user);

        $this->assertTrue($this->svc()->rejectAndRelease($r->fresh(), $this->user, 'need correction'));
        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(5, $p->eligibleCount, 'Released referrals are eligible again');
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertSame('release', $r->fresh()->reject_disposition);
    }

    public function test_reject_and_consume_does_not_restore_eligibility(): void
    {
        $this->approveConversions(5);
        $r = $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        $this->svc()->rejectAndConsume($r->fresh(), $this->user, 'abuse');

        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount, 'Consumed referrals remain unclaimable');
        $this->assertSame('consume', $r->fresh()->reject_disposition);
    }

    public function test_paid_reversal_does_not_release_referrals(): void
    {
        $this->approveConversions(5);
        $r = $this->svc()->claim($this->profile, $this->tier(5), $this->user);
        app(\App\Domain\Rewards\RewardsEngine::class)->approve($r->fresh(), $this->user);
        app(\App\Domain\Rewards\RewardsEngine::class)->markPaid($r->fresh(), $this->user);
        app(\App\Domain\Rewards\RewardsEngine::class)->reverse($r->fresh(), $this->user, 'chargeback');

        $p = $this->svc()->progressFor($this->profile);
        $this->assertSame(0, $p->eligibleCount, 'Referrals stay consumed after paid reversal');
        $this->assertSame(5, ReferralAllocation::query()
            ->where('reward_id', $r->id)->whereNotNull('active_marker')->count());
    }

    public function test_old_every_n_rule_no_longer_double_mints(): void
    {
        // Seeded legacy rule was deactivated by migration.
        $active = RewardRule::query()->where('is_active', true)->count();
        $this->assertSame(0, $active, 'No active legacy rules remain');

        $this->approveConversions(5);
        // No auto-created rewards because the legacy rule is inactive.
        $this->assertSame(0, Reward::count());
    }

    public function test_historical_rewards_remain_visible(): void
    {
        // A synthetic legacy reward with origin=legacy_rule.
        $rule = RewardRule::factory()->create(['is_active' => false]);
        Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'reward_rule_id' => $rule->id,
            'origin' => 'legacy_rule',
            'milestone_index' => 1,
            'amount_minor' => 5000,
            'status' => 'paid',
        ]);
        $this->assertSame(1, Reward::query()->where('origin', 'legacy_rule')->count());
    }

    public function test_flagged_ambassador_cannot_claim(): void
    {
        $this->approveConversions(5);
        $this->profile->update(['flagged_for_review' => true]);
        $this->expectException(MilestoneClaimUnavailableException::class);
        $this->svc()->claim($this->profile->fresh(), $this->tier(5), $this->user);
    }

    public function test_inactive_user_cannot_claim(): void
    {
        $this->approveConversions(5);
        $this->user->update(['is_active' => false]);
        $this->expectException(MilestoneClaimUnavailableException::class);
        $this->svc()->claim($this->profile->fresh(), $this->tier(5), $this->user);
    }

    public function test_inactive_tier_cannot_be_claimed(): void
    {
        $this->approveConversions(5);
        $this->tier(5)->update(['is_active' => false]);
        $this->expectException(MilestoneClaimUnavailableException::class);
        $this->svc()->claim($this->profile, $this->tier(5)->fresh(), $this->user);
    }
}
