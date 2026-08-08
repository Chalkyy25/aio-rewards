<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\Events\RewardApproved;
use App\Domain\Rewards\Events\RewardPaid;
use App\Domain\Rewards\Events\RewardReversed;
use App\Domain\Rewards\RewardFundingIntegrityException;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
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
        RewardRule::query()->delete();
        // Factory may request is_active=true, but model boot forces every_n_cash inactive.
        $this->rule = RewardRule::factory()->create([
            'trigger_count' => 5,
            'amount_minor' => 5000,
            'is_active' => true,
        ]);
        $this->assertFalse($this->rule->fresh()->is_active);
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

    public function test_legacy_auto_reward_path_is_disabled_even_with_seeded_rule_row(): void
    {
        // Attempt to force-activate via query bypass of model boot (simulating DB tampering).
        RewardRule::query()->whereKey($this->rule->id)->update(['is_active' => 1]);
        $this->assertSame(1, (int) RewardRule::query()->whereKey($this->rule->id)->value('is_active'));

        $last = $this->approveConversions(5);

        // Listener removed + onConversionApproved is a no-op.
        $this->assertSame(0, Reward::count());
        $this->assertSame([], app(RewardsEngine::class)->onConversionApproved($last));
    }

    public function test_legacy_rule_cannot_be_reactivated_via_eloquent(): void
    {
        $this->rule->update(['is_active' => true]);
        $this->assertFalse($this->rule->fresh()->is_active);
    }

    public function test_manual_approve_transitions_and_dispatches_event(): void
    {
        Event::fake([RewardApproved::class]);
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'origin' => 'legacy_rule',
        ]);
        $admin = User::factory()->create();

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $admin));
        $this->assertSame('approved', $reward->fresh()->status);
        $this->assertSame($admin->id, $reward->fresh()->approved_by_user_id);
        Event::assertDispatched(RewardApproved::class);
    }

    public function test_mark_paid_only_from_approved_state_for_bank_transfer(): void
    {
        Event::fake([RewardPaid::class]);
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'origin' => 'legacy_rule',
        ]);

        $this->assertFalse(app(RewardsEngine::class)->markPaid($reward));

        app(RewardsEngine::class)->approve($reward);
        $this->assertTrue(app(RewardsEngine::class)->markPaid(
            $reward->fresh(),
            paymentMethod: PayoutMethod::BankTransfer->value,
        ));

        $this->assertSame('paid', $reward->fresh()->status);
        Event::assertDispatched(RewardPaid::class);
    }

    public function test_mark_paid_refuses_account_credit_method(): void
    {
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'origin' => 'legacy_rule',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->assertFalse(app(RewardsEngine::class)->markPaid(
            $reward,
            paymentMethod: PayoutMethod::AccountCredit->value,
        ));
        $this->assertSame('approved', $reward->fresh()->status);
    }

    public function test_reverse_only_from_paid_state(): void
    {
        Event::fake([RewardReversed::class]);
        $pending = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'status' => 'pending_approval',
            'origin' => 'legacy_rule',
        ]);
        $approved = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'status' => 'approved',
            'milestone_index' => 2,
            'origin' => 'legacy_rule',
        ]);
        $paid = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'status' => 'paid',
            'milestone_index' => 3,
            'origin' => 'legacy_rule',
            'paid_at' => now(),
            'payment_method' => PayoutMethod::BankTransfer->value,
        ]);

        $this->assertFalse(app(RewardsEngine::class)->reverse($pending));
        $this->assertFalse(app(RewardsEngine::class)->reverse($approved));
        $this->assertTrue(app(RewardsEngine::class)->reverse($paid, note: 'refund'));
        $this->assertSame('reversed', $paid->fresh()->status);
        $this->assertSame(PayoutMethod::BankTransfer->value, $paid->fresh()->payment_method);
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

    public function test_double_approve_is_idempotent_no_duplicate_event(): void
    {
        Event::fake([RewardApproved::class]);
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'origin' => 'legacy_rule',
        ]);

        $this->assertTrue(app(RewardsEngine::class)->approve($reward));
        $this->assertFalse(app(RewardsEngine::class)->approve($reward->fresh()));
        Event::assertDispatchedTimes(RewardApproved::class, 1);
    }

    public function test_approve_refuses_compromised_funding_flag(): void
    {
        $reward = Reward::factory()->for($this->profile, 'ambassadorProfile')->for($this->rule, 'rule')->create([
            'origin' => 'legacy_rule',
            'funding_compromised_at' => now(),
            'funding_compromise_reason' => 'refund',
        ]);

        $this->expectException(RewardFundingIntegrityException::class);
        app(RewardsEngine::class)->approve($reward);
    }

    public function test_manual_conversion_approval_requires_paid_purchase(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'refunded',
            'ambassador_profile_id_snapshot' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
        ]);
        $conv = ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
            'status' => 'pending',
            'amount_minor' => 6000,
            'currency' => 'gbp',
            'pending_until' => now()->subDay(),
        ]);

        $this->assertFalse(app(ConversionService::class)->approve($conv));
        $this->assertSame('pending', $conv->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'conversion.approved')->exists());
    }
}
