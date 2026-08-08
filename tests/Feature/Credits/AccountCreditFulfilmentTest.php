<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\AccountCreditFulfilmentService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Domain\Rewards\RewardFundingIntegrityException;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Models\AccountCreditTransaction;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountCreditFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private AmbassadorProfile $profile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->member = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->member->assignRole(Role::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->for($this->member)->create([
            'flagged_for_review' => false,
        ]);
        MemberPayoutProfile::factory()->forProfile($this->profile)->create([
            'preferred_method' => PayoutMethod::AccountCredit,
        ]);

        $this->admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->admin->assignRole(Role::Admin->value);
    }

    private function approveConversions(int $n): void
    {
        $svc = app(ConversionService::class);
        for ($i = 1; $i < $n + 1; $i++) {
            $package = Package::factory()->create();
            $purchase = Purchase::factory()->create([
                'package_id' => $package->id,
                'status' => 'paid',
                'fulfilment_status' => 'completed',
                'paid_at' => now()->subDays(20),
                'ambassador_profile_id_snapshot' => $this->profile->id,
                'referral_code_snapshot' => $this->profile->referral_code,
                'buyer_email' => 'buyer-'.$i.'-'.uniqid().'@example.test',
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

    private function approvedMilestoneReward(int $threshold = 5): Reward
    {
        $this->approveConversions($threshold);
        $tier = RewardMilestoneTier::query()->where('threshold', $threshold)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));

        return $reward->fresh();
    }

    public function test_fifty_pound_reward_creates_base_plus_tier_bonus_credit(): void
    {
        $reward = $this->approvedMilestoneReward(5);
        $this->assertSame(5000, $reward->amount_minor);
        $this->assertSame(1000, $reward->account_credit_bonus_minor_snapshot);

        $ok = app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);
        $this->assertTrue($ok);

        $reward->refresh();
        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->payment_method);
        $this->assertNotNull($reward->paid_at);
        $this->assertSame($this->admin->id, $reward->paid_by_user_id);
        $this->assertNotNull($reward->account_credit_transaction_id);

        $this->assertSame(2, AccountCreditTransaction::count());
        $base = AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->sole();
        $bonus = AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->sole();
        $this->assertSame(5000, $base->amount_minor);
        $this->assertSame(1000, $bonus->amount_minor);
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_reward_cannot_credit_twice(): void
    {
        $reward = $this->approvedMilestoneReward();
        $svc = app(AccountCreditFulfilmentService::class);

        $this->assertTrue($svc->apply($reward, $this->admin));
        $this->assertTrue($svc->apply($reward->fresh(), $this->admin)); // idempotent

        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->count());
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->count());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_failed_credit_does_not_mark_reward_paid(): void
    {
        $reward = $this->approvedMilestoneReward();

        $reward->update([
            'funding_compromised_at' => now(),
            'funding_compromise_reason' => 'refund',
        ]);

        try {
            app(AccountCreditFulfilmentService::class)->apply($reward->fresh(), $this->admin);
            $this->fail('Expected funding integrity exception');
        } catch (RewardFundingIntegrityException) {
            // expected
        }

        $this->assertSame('approved', $reward->fresh()->status);
        $this->assertSame(0, AccountCreditTransaction::count());
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_invalidly_funded_reward_cannot_credit(): void
    {
        $reward = $this->approvedMilestoneReward();

        $allocation = ReferralAllocation::query()->where('reward_id', $reward->id)->whereNotNull('active_marker')->firstOrFail();
        $conversion = $allocation->conversion;
        $conversion->purchase->update(['status' => 'refunded']);
        app(ConversionService::class)->reverse($conversion, 'refund');

        $reward->refresh();
        $this->assertSame('rejected', $reward->status);

        $bad = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'currency' => 'gbp',
            'milestone_index' => 5,
            'tier_snapshot' => ['threshold' => 5],
            'reward_rule_id' => null,
        ]);

        $this->expectException(RewardFundingIntegrityException::class);
        app(AccountCreditFulfilmentService::class)->apply($bad, $this->admin);
    }

    public function test_bank_transfer_mark_paid_unchanged_and_does_not_credit(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();

        $reward = $this->approvedMilestoneReward();
        $this->assertTrue(app(RewardsEngine::class)->markPaid(
            $reward,
            $this->admin,
            'Paid externally',
            PayoutMethod::BankTransfer->value,
            'FP-1',
        ));

        $this->assertSame('paid', $reward->fresh()->status);
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->fresh()->payment_method);
        $this->assertSame(0, AccountCreditTransaction::count());
        $this->assertSame(5000, $reward->fresh()->amount_minor);
    }

    public function test_member_can_view_balance_and_history(): void
    {
        $reward = $this->approvedMilestoneReward();
        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);

        $this->actingAs($this->member)
            ->get(route('ambassador.account-credit'))
            ->assertOk()
            ->assertSee('£60.00')
            ->assertSee('Reward Credit')
            ->assertSee('Milestone Bonus')
            ->assertSee('Browse packages');
    }

    public function test_concurrent_idempotent_retry_does_not_double_credit(): void
    {
        $reward = $this->approvedMilestoneReward();
        $svc = app(AccountCreditFulfilmentService::class);

        DB::transaction(fn () => $svc->apply($reward, $this->admin));
        DB::transaction(fn () => $svc->apply($reward->fresh(), $this->admin));

        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('reward_id', $reward->id)
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)
            ->count());
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('reward_id', $reward->id)
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)
            ->count());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_orphaned_credit_is_repaired_including_missing_bonus_and_bank_mark_paid_blocked(): void
    {
        $reward = $this->approvedMilestoneReward();

        // Incomplete fulfilment: base ledger credit exists, bonus missing, reward still approved.
        $tx = app(AccountCreditLedger::class)->creditRewardFulfilment(
            profile: $this->profile,
            amountMinor: $reward->amount_minor,
            currency: $reward->currency,
            rewardId: $reward->id,
            actor: $this->admin,
        );

        $this->assertSame('approved', $reward->fresh()->status);
        $this->assertNull($reward->fresh()->account_credit_transaction_id);
        $this->assertSame(1, AccountCreditTransaction::count());

        $ok = app(AccountCreditFulfilmentService::class)->apply($reward->fresh(), $this->admin);
        $this->assertTrue($ok);

        $reward->refresh();
        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->payment_method);
        $this->assertSame($tx->id, $reward->account_credit_transaction_id);
        $this->assertNotNull($reward->paid_at);
        $this->assertSame(2, AccountCreditTransaction::count());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        $this->assertFalse(app(RewardsEngine::class)->markPaid(
            $reward->fresh(),
            $this->admin,
            paymentMethod: PayoutMethod::BankTransfer->value,
            paymentReference: 'SHOULD-FAIL',
        ));
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->fresh()->payment_method);
        $this->assertSame($tx->id, $reward->fresh()->account_credit_transaction_id);
    }

    public function test_apply_requires_account_credit_payout_preference(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();

        $reward = $this->approvedMilestoneReward();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account Credit fulfilment requires the member preferred payout method to be Account Credit.');
        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);
    }

    public function test_apply_succeeds_when_preference_is_account_credit(): void
    {
        $reward = $this->approvedMilestoneReward();
        $this->assertSame(
            PayoutMethod::AccountCredit,
            $this->profile->fresh()->payoutProfile->preferred_method,
        );
        $this->assertTrue(app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin));
        $this->assertSame('paid', $reward->fresh()->status);
    }
}
