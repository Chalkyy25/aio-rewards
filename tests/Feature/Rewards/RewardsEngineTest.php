<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\Events\RewardApproved;
use App\Domain\Rewards\Events\RewardCreated;
use App\Domain\Rewards\Events\RewardPaid;
use App\Domain\Rewards\Events\RewardReversed;
use App\Domain\Rewards\RewardsEngine;
use App\Models\AmbassadorProfile;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RewardsEngineTest extends TestCase
{
    use RefreshDatabase;

    private RewardRule $rule;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->profile = AmbassadorProfile::factory()->for($user)->create([
            'flagged_for_review' => false,
        ]);
        // Wipe the migration-seeded default rule so we test in isolation.
        RewardRule::query()->delete();
        $this->rule = RewardRule::factory()->create([
            'trigger_count' => 5,
            'amount_minor' => 5000,
        ]);
    }

    /** Approve $n conversions and return the last one. */
    private function approveConversions(int $n): ReferralConversion
    {
        $svc = app(ConversionService::class);
        $last = null;
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
            $last = $conv->fresh();
        }

        return $last;
    }

    public function test_four_approved_conversions_do_not_create_a_reward(): void
    {
        $this->approveConversions(4);
        $this->assertSame(0, Reward::count());
    }

    public function test_fifth_approved_conversion_creates_the_first_reward(): void
    {
        Event::fake([RewardCreated::class]);

        $last = $this->approveConversions(5);

        $reward = Reward::sole();
        $this->assertSame($this->profile->id, $reward->ambassador_profile_id);
        $this->assertSame($this->rule->id, $reward->reward_rule_id);
        $this->assertSame(1, $reward->milestone_index);
        $this->assertSame(5000, $reward->amount_minor);
        $this->assertSame('pending_approval', $reward->status);
        $this->assertSame($last->id, $reward->trigger_conversion_id);

        Event::assertDispatched(RewardCreated::class, fn (RewardCreated $e) => $e->reward->id === $reward->id);
        $this->assertTrue(AuditLog::where('action', 'reward.created')->exists());
    }

    public function test_tenth_approved_conversion_creates_the_second_reward(): void
    {
        $this->approveConversions(10);

        $this->assertSame(2, Reward::count());
        $indexes = Reward::pluck('milestone_index')->sort()->values()->all();
        $this->assertSame([1, 2], $indexes);
    }

    public function test_engine_is_idempotent_when_called_twice_on_same_conversion(): void
    {
        $last = $this->approveConversions(5);

        // Direct engine call again with the same conversion must not double-create.
        app(RewardsEngine::class)->onConversionApproved($last);
        app(RewardsEngine::class)->onConversionApproved($last);

        $this->assertSame(1, Reward::count());
    }

    public function test_reversed_conversion_never_creates_a_reward(): void
    {
        // Approve 5 conversions then reverse the last one before firing the engine.
        $last = $this->approveConversions(4);
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'completed',
            'ambassador_profile_id_snapshot' => $this->profile->id,
        ]);
        $c = ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
            'status' => 'reversed', // never approved
            'amount_minor' => 6000,
            'currency' => 'gbp',
            'reversed_at' => now(),
            'reversed_reason' => 'refund',
        ]);

        app(RewardsEngine::class)->onConversionApproved($c);
        $this->assertSame(0, Reward::count());
    }

    public function test_inactive_ambassador_never_earns_rewards(): void
    {
        $this->profile->user->update(['is_active' => false]);
        $this->approveConversions(5);
        $this->assertSame(0, Reward::count());
    }

    public function test_flagged_ambassador_never_earns_rewards(): void
    {
        $this->profile->update(['flagged_for_review' => true]);
        $this->approveConversions(5);
        $this->assertSame(0, Reward::count());
    }

    public function test_manual_approve_transitions_and_dispatches_event(): void
    {
        Event::fake([RewardApproved::class]);
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create();
        $admin = User::factory()->create();

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $admin));
        $this->assertSame('approved', $reward->fresh()->status);
        $this->assertSame($admin->id, $reward->fresh()->approved_by_user_id);
        Event::assertDispatched(RewardApproved::class);
    }

    public function test_mark_paid_only_from_approved_state(): void
    {
        Event::fake([RewardPaid::class]);
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create();

        // Cannot pay from pending_approval.
        $this->assertFalse(app(RewardsEngine::class)->markPaid($reward));

        app(RewardsEngine::class)->approve($reward);
        $this->assertTrue(app(RewardsEngine::class)->markPaid($reward->fresh()));

        $this->assertSame('paid', $reward->fresh()->status);
        Event::assertDispatched(RewardPaid::class);
    }

    public function test_reverse_dispatches_event_and_flips_status(): void
    {
        Event::fake([RewardReversed::class]);
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create(['status' => 'paid']);

        $this->assertTrue(app(RewardsEngine::class)->reverse($reward, note: 'refund'));
        $this->assertSame('reversed', $reward->fresh()->status);
        Event::assertDispatched(RewardReversed::class);
    }

    public function test_reject_can_move_from_pending_or_approved(): void
    {
        $r1 = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create(['status' => 'pending_approval']);
        $r2 = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create(['status' => 'approved', 'milestone_index' => 2]);
        $r3 = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create(['status' => 'paid', 'milestone_index' => 3]);

        $this->assertTrue(app(RewardsEngine::class)->reject($r1));
        $this->assertTrue(app(RewardsEngine::class)->reject($r2));
        $this->assertFalse(app(RewardsEngine::class)->reject($r3));
    }

    public function test_only_active_rules_are_evaluated(): void
    {
        $this->rule->update(['is_active' => false]);
        $this->approveConversions(5);
        $this->assertSame(0, Reward::count());
    }
}
