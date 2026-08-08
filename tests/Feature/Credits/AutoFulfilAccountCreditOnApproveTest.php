<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\AccountCreditFulfilmentService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Models\AccountCreditTransaction;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoFulfilAccountCreditOnApproveTest extends TestCase
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

        $this->admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->admin->assignRole(Role::Admin->value);
    }

    private function setAccountCreditPreference(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->create([
            'preferred_method' => PayoutMethod::AccountCredit,
        ]);
    }

    private function setBankPreference(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();
    }

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

    private function claimPending(int $threshold = 5): Reward
    {
        $this->approveConversions($threshold);
        $tier = RewardMilestoneTier::query()->where('threshold', $threshold)->firstOrFail();

        return app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
    }

    public function test_account_credit_approve_pays_and_credits_sixty(): void
    {
        $this->setAccountCreditPreference();
        $reward = $this->claimPending(5);
        $this->assertSame(PayoutMethod::AccountCredit, $reward->preferred_payout_method_snapshot);
        $this->assertSame('pending_approval', $reward->status);

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));

        $fresh = $reward->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->approved_by_user_id);
        $this->assertSame($this->admin->id, $fresh->paid_by_user_id);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(PayoutMethod::AccountCredit->value, $fresh->payment_method);
        $this->assertNotNull($fresh->account_credit_transaction_id);

        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->count());
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->count());
        $this->assertSame(5000, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->value('amount_minor'));
        $this->assertSame(1000, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->value('amount_minor'));
        $this->assertSame($this->admin->id, AccountCreditTransaction::query()->value('actor_user_id'));
    }

    public function test_duplicate_approve_does_not_duplicate_credit(): void
    {
        $this->setAccountCreditPreference();
        $reward = $this->claimPending(5);

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));
        $this->assertFalse(app(RewardsEngine::class)->approve($reward->fresh(), $this->admin));

        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->count());
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->count());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_bank_transfer_approve_awaits_payment_without_ledger(): void
    {
        $this->setBankPreference();
        $reward = $this->claimPending(5);
        $this->assertSame(PayoutMethod::BankTransfer, $reward->preferred_payout_method_snapshot);

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));

        $fresh = $reward->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($this->admin->id, $fresh->approved_by_user_id);
        $this->assertNull($fresh->paid_at);
        $this->assertNull($fresh->payment_method);
        $this->assertSame(0, AccountCreditTransaction::count());
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_null_snapshot_approve_does_not_invent_account_credit(): void
    {
        $this->setAccountCreditPreference();
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'legacy_rule',
            'status' => 'pending_approval',
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => null,
            'currency' => 'gbp',
            'milestone_index' => 1,
            'reward_rule_id' => null,
        ]);

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));

        $fresh = $reward->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(0, AccountCreditTransaction::count());
    }

    public function test_live_preference_change_after_claim_does_not_affect_approve_path(): void
    {
        $this->setAccountCreditPreference();
        $reward = $this->claimPending(5);
        $this->assertSame(PayoutMethod::AccountCredit, $reward->preferred_payout_method_snapshot);

        $this->setBankPreference();

        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));

        $fresh = $reward->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $fresh->payment_method);
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_fulfilment_failure_rolls_back_approval(): void
    {
        $this->setAccountCreditPreference();
        $reward = $this->claimPending(5);

        $this->mock(AccountCreditFulfilmentService::class, function ($mock) {
            $mock->shouldReceive('apply')->once()->andReturn(false);
        });

        try {
            app(RewardsEngine::class)->approve($reward, $this->admin);
            $this->fail('Expected RuntimeException when Account Credit fulfilment fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Account Credit fulfilment failed', $e->getMessage());
        }

        $fresh = $reward->fresh();
        $this->assertSame('pending_approval', $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approved_by_user_id);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(0, AccountCreditTransaction::count());
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }
}
