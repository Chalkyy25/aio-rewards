<?php

namespace Tests\Feature\Rewards;

use App\Domain\Credits\AccountCreditFulfilmentService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneClaimUnavailableException;
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

class RewardClaimPayoutMethodSnapshotTest extends TestCase
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

    private function approveConversions(int $n): void
    {
        $svc = app(ConversionService::class);
        for ($i = 0; $i < $n; $i++) {
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

    private function setAccountCreditPreference(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->accountCredit()->create();
    }

    private function setBankPreference(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();
    }

    public function test_account_credit_preference_snapshots_method_and_keeps_cash_amount(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();

        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);

        $this->assertSame(PayoutMethod::AccountCredit, $reward->preferred_payout_method_snapshot);
        $this->assertSame(5000, $reward->amount_minor);
        $this->assertSame(1000, $reward->account_credit_bonus_minor_snapshot);
        $this->assertSame(6000, $reward->accountCreditTotalMinor());
        $this->assertSame(6000, $reward->memberFacingAmountMinor());
    }

    public function test_milestones_ui_shows_claim_sixty_account_credit_cta(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);

        $this->actingAs($this->member)
            ->get('/ambassador/rewards/milestones')
            ->assertOk()
            ->assertSee('data-testid="claim-cta-5"', false)
            ->assertSeeText('Claim £60 Account Credit')
            ->assertSeeText('Change payout method');
    }

    public function test_bank_transfer_preference_claims_fifty_cash(): void
    {
        $this->setBankPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();

        $this->actingAs($this->member)
            ->get('/ambassador/rewards/milestones')
            ->assertOk()
            ->assertSeeText('Cash out £50');

        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);

        $this->assertSame(PayoutMethod::BankTransfer, $reward->preferred_payout_method_snapshot);
        $this->assertSame(5000, $reward->amount_minor);
        $this->assertSame(1000, $reward->account_credit_bonus_minor_snapshot); // still snapshotted
        $this->assertSame(5000, $reward->memberFacingAmountMinor()); // bonus not payable
    }

    public function test_changing_preference_after_claim_does_not_change_snapshot(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);

        $this->setBankPreference();

        $reward->refresh();
        $this->assertSame(PayoutMethod::AccountCredit, $reward->preferred_payout_method_snapshot);
        $this->assertTrue($reward->prefersAccountCredit());
        $this->assertSame(PayoutMethod::BankTransfer, $this->profile->fresh()->payoutProfile->preferred_method);
    }

    public function test_changing_tier_bonus_after_claim_does_not_change_snapshot(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);

        $tier->update(['account_credit_bonus_minor' => 9999]);

        $this->assertSame(1000, $reward->fresh()->account_credit_bonus_minor_snapshot);
        $this->assertSame(6000, $reward->fresh()->accountCreditTotalMinor());
    }

    public function test_admin_actions_follow_claim_snapshot_not_live_preference(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        app(RewardsEngine::class)->approve($reward, $this->admin);
        $reward->refresh();

        $this->setBankPreference();

        $this->assertSame(PayoutMethod::AccountCredit, $reward->fulfilmentPayoutMethod());
        $this->assertTrue($reward->prefersAccountCredit());
        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->payment_method);

        // Idempotent re-apply after live preference switched to bank.
        $this->assertTrue(app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin));
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->count());
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->count());
    }

    public function test_missing_payout_method_blocks_claim(): void
    {
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();

        $this->actingAs($this->member)
            ->get('/ambassador/rewards/milestones')
            ->assertOk()
            ->assertSee('data-testid="claim-requires-payout"', false)
            ->assertSee("Choose how you'd like to receive rewards before claiming", false)
            ->assertSee('data-testid="set-payout-method-cta"', false)
            ->assertDontSee('data-testid="claim-cta-5"', false);

        $this->expectException(MilestoneClaimUnavailableException::class);
        app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
    }

    public function test_bank_transfer_fulfilment_never_posts_account_credit_bonus(): void
    {
        $this->setBankPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        app(RewardsEngine::class)->approve($reward, $this->admin);

        $this->assertTrue(app(RewardsEngine::class)->markPaid(
            $reward->fresh(),
            $this->admin,
            paymentMethod: PayoutMethod::BankTransfer->value,
            paymentReference: 'BT-1',
        ));

        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(0, AccountCreditTransaction::count());
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->fresh()->payment_method);
    }

    public function test_dashboard_and_history_show_account_credit_pending_sixty(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);

        $this->actingAs($this->member)
            ->get('/ambassador/dashboard')
            ->assertOk()
            ->assertSeeText('Pending Account Credit')
            ->assertSee('data-testid="reward-pending"', false)
            ->assertSeeText('£60.00')
            ->assertSeeText('£50.00 reward + £10.00 bonus');

        $this->actingAs($this->member)
            ->get('/ambassador/rewards/history')
            ->assertOk()
            ->assertSeeText('Pending Account Credit')
            ->assertSee('data-testid="history-amount-'.$reward->id.'"', false)
            ->assertSeeText('£60.00');
    }

    public function test_legacy_null_snapshot_does_not_invent_account_credit_display(): void
    {
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => null,
            'status' => 'pending_approval',
            'origin' => 'milestone_claim',
        ]);

        $this->assertNull($reward->claimedPayoutMethod());
        $this->assertSame(5000, $reward->memberFacingAmountMinor());
        $this->assertSame('Pending reward', $reward->memberStatusHeadline());
    }

    public function test_mark_paid_refuses_account_credit_snapshot_even_with_bank_override(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        app(RewardsEngine::class)->approve($reward, $this->admin);
        $reward->refresh();

        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->payment_method);

        $this->assertFalse(app(RewardsEngine::class)->markPaid(
            $reward,
            $this->admin,
            paymentMethod: PayoutMethod::BankTransfer->value,
        ));
        $this->assertFalse(app(RewardsEngine::class)->markPaid($reward->fresh(), $this->admin));
        $this->assertSame('paid', $reward->fresh()->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->fresh()->payment_method);
    }

    public function test_mark_paid_honours_bank_snapshot_and_ignores_account_credit_override(): void
    {
        $this->setBankPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        app(RewardsEngine::class)->approve($reward, $this->admin);
        $reward->refresh();

        $this->assertTrue(app(RewardsEngine::class)->markPaid(
            $reward,
            $this->admin,
            paymentMethod: PayoutMethod::BankTransfer->value,
            paymentReference: 'BT-OK',
        ));
        $this->assertSame('paid', $reward->fresh()->status);
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->fresh()->payment_method);
    }

    public function test_mark_paid_bank_snapshot_ignores_explicit_account_credit_argument(): void
    {
        $this->setBankPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        app(RewardsEngine::class)->approve($reward, $this->admin);

        $this->assertTrue(app(RewardsEngine::class)->markPaid(
            $reward->fresh(),
            $this->admin,
            paymentMethod: PayoutMethod::AccountCredit->value,
            paymentReference: 'SHOULD-STAY-BANK',
        ));
        $fresh = $reward->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(PayoutMethod::BankTransfer->value, $fresh->payment_method);
        $this->assertSame(0, AccountCreditTransaction::count());
    }

    public function test_mark_paid_still_refuses_after_live_preference_switch_from_ac_claim(): void
    {
        $this->setAccountCreditPreference();
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        app(RewardsEngine::class)->approve($reward, $this->admin);

        $this->setBankPreference();

        $this->assertFalse(app(RewardsEngine::class)->markPaid(
            $reward->fresh(),
            $this->admin,
            paymentMethod: PayoutMethod::BankTransfer->value,
        ));
        $this->assertSame('paid', $reward->fresh()->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->fresh()->payment_method);
        $this->assertSame(PayoutMethod::AccountCredit, $reward->fresh()->preferred_payout_method_snapshot);
    }

    public function test_historical_null_snapshot_mark_paid_uses_legacy_fallback(): void
    {
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
            'preferred_payout_method_snapshot' => null,
            'origin' => 'legacy_rule',
        ]);

        $this->assertTrue(app(RewardsEngine::class)->markPaid($reward, $this->admin));
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->fresh()->payment_method);

        $acLegacy = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
            'preferred_payout_method_snapshot' => null,
            'origin' => 'legacy_rule',
            'milestone_index' => 2,
        ]);

        $this->assertFalse(app(RewardsEngine::class)->markPaid(
            $acLegacy,
            $this->admin,
            paymentMethod: PayoutMethod::AccountCredit->value,
        ));
        $this->assertSame('approved', $acLegacy->fresh()->status);
    }
}
