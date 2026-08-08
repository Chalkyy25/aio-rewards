<?php

namespace Tests\Feature\Rewards;

use App\Domain\Credits\AccountCreditFulfilmentService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Domain\Rewards\RewardsEngine;
use App\Enums\OperationsType;
use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\OperationsItem;
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

class RewardFundingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private AmbassadorProfile $profile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->member->assignRole(Role::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->for($this->member)->create(['flagged_for_review' => false]);
        MemberPayoutProfile::factory()->forProfile($this->profile)->accountCredit()->create();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Role::Admin->value);
    }

    private function approveConversions(int $n): array
    {
        $svc = app(ConversionService::class);
        $conversions = [];
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
            $conversions[] = $conv->fresh();
        }

        return $conversions;
    }

    private function claimPending(): Reward
    {
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();

        return app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);
    }

    public function test_refund_invalidates_pending_reward_and_releases_allocations(): void
    {
        $reward = $this->claimPending();
        $allocation = ReferralAllocation::query()->where('reward_id', $reward->id)->whereNotNull('active_marker')->firstOrFail();
        $conversion = $allocation->conversion;
        $conversion->purchase->update(['status' => 'refunded']);

        app(ConversionService::class)->reverse($conversion, 'refund');

        $reward->refresh();
        $this->assertSame('rejected', $reward->status);
        $this->assertSame('release', $reward->reject_disposition);
        $this->assertNotNull($reward->funding_compromised_at);
        $this->assertSame($conversion->id, $reward->funding_compromise_conversion_id);
        $this->assertSame(0, ReferralAllocation::query()
            ->where('reward_id', $reward->id)->whereNotNull('active_marker')->count());

        // Remaining valid referrals return to eligible pool (4 left).
        $progress = app(MilestoneProgressionService::class)->progressFor($this->profile);
        $this->assertSame(4, $progress->eligibleCount);
    }

    public function test_refund_invalidates_approved_unpaid_reward(): void
    {
        $reward = $this->claimPending();
        app(RewardsEngine::class)->approve($reward, $this->admin);

        $allocation = ReferralAllocation::query()->where('reward_id', $reward->id)->whereNotNull('active_marker')->firstOrFail();
        $conversion = $allocation->conversion;
        $conversion->purchase->update(['status' => 'refunded']);
        app(ConversionService::class)->reverse($conversion, 'refund');

        $this->assertSame('rejected', $reward->fresh()->status);
        $this->assertFalse(app(RewardsEngine::class)->markPaid($reward->fresh(), paymentMethod: PayoutMethod::BankTransfer->value));
    }

    public function test_refund_after_bank_transfer_paid_flags_for_review_without_erasing_payment(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();
        $reward = $this->claimPending();
        app(RewardsEngine::class)->approve($reward, $this->admin);
        app(RewardsEngine::class)->markPaid($reward->fresh(), $this->admin, paymentMethod: PayoutMethod::BankTransfer->value, paymentReference: 'BT-1');

        $allocation = ReferralAllocation::query()->where('reward_id', $reward->id)->whereNotNull('active_marker')->firstOrFail();
        $conversion = $allocation->conversion;
        $conversion->purchase->update(['status' => 'refunded']);
        app(ConversionService::class)->reverse($conversion, 'refund');

        $reward->refresh();
        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::BankTransfer->value, $reward->payment_method);
        $this->assertSame('BT-1', $reward->payment_reference);
        $this->assertNotNull($reward->funding_compromised_at);
        $this->assertSame($conversion->id, $reward->funding_compromise_conversion_id);

        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::RewardPaidFundingCompromised->value,
            'subject_id' => $reward->id,
        ]);
        $item = OperationsItem::query()->where('subject_id', $reward->id)
            ->where('type', OperationsType::RewardPaidFundingCompromised->value)->first();
        $this->assertSame($conversion->id, $item->meta['conversion_id']);
    }

    public function test_chargeback_after_account_credit_paid_flags_without_auto_debit(): void
    {
        // setUp already configures Account Credit preference.
        $reward = $this->claimPending();
        app(RewardsEngine::class)->approve($reward, $this->admin);
        app(AccountCreditFulfilmentService::class)->apply($reward->fresh(), $this->admin);

        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        $allocation = ReferralAllocation::query()->where('reward_id', $reward->id)->whereNotNull('active_marker')->firstOrFail();
        $conversion = $allocation->conversion;
        $conversion->purchase->update(['status' => 'chargeback']);
        app(ConversionService::class)->reverse($conversion, 'chargeback');

        $reward->refresh();
        $this->assertSame('paid', $reward->status);
        $this->assertSame(PayoutMethod::AccountCredit->value, $reward->payment_method);
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile), 'No automatic clawback debit');
        $this->assertNotNull($reward->funding_compromised_at);
        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::RewardPaidFundingCompromised->value,
            'subject_id' => $reward->id,
        ]);
    }

    public function test_approved_referral_cannot_generate_both_legacy_and_milestone_reward(): void
    {
        // Even if a legacy rule row is force-activated at DB level...
        RewardRule::query()->delete();
        $ruleId = RewardRule::factory()->create(['is_active' => false])->id;
        RewardRule::query()->whereKey($ruleId)->update(['is_active' => 1]);

        $this->approveConversions(5);
        $this->assertSame(0, Reward::count(), 'No legacy auto reward');

        $tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $claimed = app(MilestoneProgressionService::class)->claim($this->profile, $tier, $this->member);

        $this->assertSame(1, Reward::count());
        $this->assertSame('milestone_claim', $claimed->origin);
        $this->assertSame(0, Reward::query()->where('origin', 'legacy_rule')->count());
    }
}
