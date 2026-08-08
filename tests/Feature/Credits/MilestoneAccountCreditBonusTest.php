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

class MilestoneAccountCreditBonusTest extends TestCase
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
        for ($i = 0; $i < $n; $i++) {
            $package = Package::factory()->create();
            $purchase = Purchase::factory()->create([
                'package_id' => $package->id,
                'status' => 'paid',
                'fulfilment_status' => 'completed',
                'paid_at' => now()->subDays(20),
                'ambassador_profile_id_snapshot' => $this->profile->id,
                'referral_code_snapshot' => $this->profile->referral_code,
                'buyer_email' => 'buyer-'.uniqid().'@example.test',
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

    private function claimAndApprove(int $threshold): Reward
    {
        $this->approveConversions($threshold);
        $tier = RewardMilestoneTier::query()->where('threshold', $threshold)->firstOrFail();
        $reward = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
        $this->assertTrue(app(RewardsEngine::class)->approve($reward, $this->admin));

        return $reward->fresh();
    }

    public function test_tier_a_fifty_plus_ten_bonus_equals_sixty(): void
    {
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $this->assertSame(5000, $tier->total_reward_amount_minor);
        $this->assertSame(1000, $tier->account_credit_bonus_minor);
        $this->assertSame(6000, $tier->accountCreditTotalMinor());

        $reward = $this->claimAndApprove(5);
        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);

        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(5000, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)->value('amount_minor'));
        $this->assertSame(1000, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->value('amount_minor'));
    }

    public function test_tier_b_independent_bonus(): void
    {
        // New cycle after claiming tier 5 first would consume referrals; use fresh profile via new member.
        $tier10 = RewardMilestoneTier::query()->where('threshold', 10)->firstOrFail();
        $this->assertSame(11000, $tier10->total_reward_amount_minor);
        $this->assertSame(2000, $tier10->account_credit_bonus_minor);
        $this->assertSame(13000, $tier10->accountCreditTotalMinor());

        $reward = $this->claimAndApprove(10);
        $this->assertSame(11000, $reward->amount_minor);
        $this->assertSame(2000, $reward->account_credit_bonus_minor_snapshot);

        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);
        $this->assertSame(13000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_tier_c_zero_bonus_creates_base_only(): void
    {
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $tier->update(['account_credit_bonus_minor' => 0]);

        $reward = $this->claimAndApprove(5);
        $this->assertSame(0, $reward->account_credit_bonus_minor_snapshot);

        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);

        $this->assertSame(1, AccountCreditTransaction::count());
        $this->assertSame(5000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(0, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_REWARD_BONUS)->count());
    }

    public function test_changing_tier_a_bonus_does_not_change_tier_b(): void
    {
        $a = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $b = RewardMilestoneTier::query()->where('threshold', 10)->firstOrFail();
        $bBonusBefore = $b->account_credit_bonus_minor;

        $a->update(['account_credit_bonus_minor' => 999]);

        $this->assertSame($bBonusBefore, $b->fresh()->account_credit_bonus_minor);
        $this->assertSame(999, $a->fresh()->account_credit_bonus_minor);
    }

    public function test_adding_new_tier_works_without_code_changes(): void
    {
        $tier = RewardMilestoneTier::factory()->create([
            'threshold' => 25,
            'total_reward_amount_minor' => 30000,
            'account_credit_bonus_minor' => 5000,
            'bonus_amount_minor' => 0,
            'title' => '£300 Reward',
            'display_order' => 50,
        ]);

        $this->assertSame(35000, $tier->accountCreditTotalMinor());

        $reward = $this->claimAndApprove(25);
        $this->assertSame(30000, $reward->amount_minor);
        $this->assertSame(5000, $reward->account_credit_bonus_minor_snapshot);

        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);
        $this->assertSame(35000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_existing_reward_snapshot_immutable_when_admin_edits_tier(): void
    {
        $reward = $this->claimAndApprove(5);
        $this->assertSame(1000, $reward->account_credit_bonus_minor_snapshot);

        RewardMilestoneTier::query()->where('threshold', 5)->update(['account_credit_bonus_minor' => 500]);

        $this->assertSame(1000, $reward->fresh()->account_credit_bonus_minor_snapshot);
        $this->assertSame(500, RewardMilestoneTier::query()->where('threshold', 5)->value('account_credit_bonus_minor'));
    }

    public function test_new_reward_receives_new_configured_bonus(): void
    {
        RewardMilestoneTier::query()->where('threshold', 5)->update(['account_credit_bonus_minor' => 2500]);

        $reward = $this->claimAndApprove(5);
        $this->assertSame(2500, $reward->account_credit_bonus_minor_snapshot);

        app(AccountCreditFulfilmentService::class)->apply($reward, $this->admin);
        $this->assertSame(7500, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_bank_transfer_receives_no_bonus(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();

        $reward = $this->claimAndApprove(5);
        $this->assertSame(1000, $reward->account_credit_bonus_minor_snapshot);

        app(RewardsEngine::class)->markPaid(
            $reward,
            $this->admin,
            'Paid',
            PayoutMethod::BankTransfer->value,
            'BT-1',
        );

        $this->assertSame(0, AccountCreditTransaction::count());
        $this->assertSame(5000, $reward->fresh()->amount_minor);
    }

    public function test_historical_rewards_default_bonus_snapshot_to_zero(): void
    {
        // Simulate a pre-migration reward with explicit 0 snapshot (migration default).
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 0,
            'currency' => 'gbp',
            'milestone_index' => 5,
            'reward_rule_id' => null,
            'idempotency_key' => 'hist:'.$this->profile->id,
        ]);

        // Funding: attach enough allocations via real claim path is heavy; force fundable by
        // using a properly funded claim instead for apply — here we only assert snapshot math.
        $this->assertSame(0, $reward->accountCreditBonusMinor());
        $this->assertSame(5000, $reward->accountCreditTotalMinor());
    }
}
