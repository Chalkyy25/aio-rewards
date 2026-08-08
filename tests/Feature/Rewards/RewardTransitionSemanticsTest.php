<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Models\AmbassadorProfile;
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

class RewardTransitionSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->member->assignRole(Role::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->for($this->member)->create(['flagged_for_review' => false]);
    }

    private function claim(): Reward
    {
        $svc = app(ConversionService::class);
        for ($i = 0; $i < 5; $i++) {
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
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();

        return app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
    }

    public function test_pending_approval_uses_reject_not_reverse(): void
    {
        $reward = $this->claim();
        $this->assertSame('pending_approval', $reward->status);

        $this->assertFalse(app(RewardsEngine::class)->reverse($reward));
        $this->assertTrue(app(MilestoneProgressionService::class)->rejectAndRelease($reward, $this->member, 'admin correction'));
        $this->assertSame('rejected', $reward->fresh()->status);
        $this->assertSame(0, ReferralAllocation::query()
            ->where('reward_id', $reward->id)->whereNotNull('active_marker')->count());
    }

    public function test_approved_unpaid_uses_reject_not_reverse(): void
    {
        $reward = $this->claim();
        app(RewardsEngine::class)->approve($reward, $this->member);

        $this->assertFalse(app(RewardsEngine::class)->reverse($reward->fresh()));
        $this->assertTrue(app(MilestoneProgressionService::class)->rejectAndConsume($reward->fresh(), $this->member, 'abuse'));
        $this->assertSame('rejected', $reward->fresh()->status);
        $this->assertSame(5, ReferralAllocation::query()
            ->where('reward_id', $reward->id)->whereNotNull('active_marker')->count());
    }

    public function test_paid_can_reverse_preserving_payment_metadata(): void
    {
        $reward = $this->claim();
        app(RewardsEngine::class)->approve($reward, $this->member);
        app(RewardsEngine::class)->markPaid(
            $reward->fresh(),
            $this->member,
            paymentMethod: PayoutMethod::BankTransfer->value,
            paymentReference: 'KEEP-ME',
        );

        $this->assertTrue(app(RewardsEngine::class)->reverse($reward->fresh(), $this->member, 'accounting reverse'));
        $fresh = $reward->fresh();
        $this->assertSame('reversed', $fresh->status);
        $this->assertSame('KEEP-ME', $fresh->payment_reference);
        $this->assertSame(PayoutMethod::BankTransfer->value, $fresh->payment_method);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(5, ReferralAllocation::query()
            ->where('reward_id', $reward->id)->whereNotNull('active_marker')->count());
    }

    public function test_rejected_and_reversed_are_terminal_for_financial_actions(): void
    {
        $reward = $this->claim();
        app(MilestoneProgressionService::class)->rejectAndRelease($reward, $this->member, 'nope');

        $this->assertFalse(app(RewardsEngine::class)->approve($reward->fresh()));
        $this->assertFalse(app(RewardsEngine::class)->markPaid($reward->fresh()));
        $this->assertFalse(app(RewardsEngine::class)->reverse($reward->fresh()));
        $this->assertFalse(app(RewardsEngine::class)->reject($reward->fresh()));
    }
}
