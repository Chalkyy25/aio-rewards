<?php

namespace Tests\Feature\Rewards;

use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Filament\Resources\RewardResource;
use App\Filament\Resources\RewardResource\Pages\ListRewards;
use App\Filament\Resources\RewardResource\Pages\ViewReward;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\Reward;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RewardAdminPayoutSnapshotUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->admin->assignRole(Role::SuperAdmin->value);

        $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $member->assignRole(Role::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->for($member)->create();
        MemberPayoutProfile::factory()->forProfile($this->profile)->accountCredit()->create();
    }

    public function test_list_and_view_show_account_credit_payable_sixty(): void
    {
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'pending_approval',
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'origin' => 'milestone_claim',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListRewards::class)
            ->assertCanSeeTableRecords([$reward])
            ->assertSee('Account Credit')
            ->assertSee('£60.00');

        $this->get(RewardResource::getUrl('view', ['record' => $reward]))
            ->assertOk()
            ->assertSeeText('Claim payout')
            ->assertSeeText('Payout Method')
            ->assertSeeText('Account Credit')
            ->assertSeeText('Cash Reward')
            ->assertSeeText('£50.00')
            ->assertSeeText('Account Credit Bonus')
            ->assertSeeText('£10.00')
            ->assertSeeText('Account Credit Total')
            ->assertSeeText('£60.00')
            ->assertSeeText('Pending approval');

        $this->assertSame(5000, $reward->fresh()->amount_minor);
    }

    public function test_list_and_view_show_bank_transfer_fifty(): void
    {
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();

        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'pending_approval',
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::BankTransfer,
            'origin' => 'milestone_claim',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListRewards::class)
            ->assertCanSeeTableRecords([$reward])
            ->assertSee('Bank Transfer')
            ->assertSee('£50.00');

        $this->get(RewardResource::getUrl('view', ['record' => $reward]))
            ->assertOk()
            ->assertSeeText('Bank Transfer')
            ->assertSeeText('Cash Reward')
            ->assertSeeText('£50.00')
            ->assertSeeText('Not applicable')
            ->assertSeeText('Payable Total')
            ->assertDontSeeText('Account Credit Total');
    }

    public function test_null_snapshot_shows_legacy_cash_only_not_live_preference(): void
    {
        // Live preference is Account Credit, but snapshot is null.
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'pending_approval',
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => null,
            'origin' => 'milestone_claim',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListRewards::class)
            ->assertCanSeeTableRecords([$reward])
            ->assertSee('Legacy / Not snapshotted')
            ->assertSee('£50.00');

        $this->get(RewardResource::getUrl('view', ['record' => $reward]))
            ->assertOk()
            ->assertSeeText('Legacy / Not snapshotted')
            ->assertSeeText('£50.00')
            ->assertSeeText('do not infer Account Credit')
            ->assertSeeText('Current member preference')
            ->assertSeeText('Account Credit');

        $this->assertSame('Legacy / Not snapshotted', $reward->adminClaimedPayoutMethodLabel());
        $this->assertSame(5000, $reward->memberFacingAmountMinor());
    }

    public function test_admin_actions_follow_claim_snapshot_not_live_preference(): void
    {
        $acReward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'origin' => 'milestone_claim',
        ]);

        // Switch live preference to bank — actions must still follow AC snapshot.
        MemberPayoutProfile::query()->where('ambassador_profile_id', $this->profile->id)->delete();
        MemberPayoutProfile::factory()->forProfile($this->profile)->bankTransfer()->create();

        $this->assertSame(PayoutMethod::AccountCredit, $acReward->fulfilmentPayoutMethod());
        $this->assertSame(PayoutMethod::AccountCredit, $acReward->fresh()->preferred_payout_method_snapshot);

        $this->actingAs($this->admin);
        Livewire::test(ViewReward::class, ['record' => $acReward->id])
            ->assertActionVisible('applyAccountCredit')
            ->assertActionHidden('markPaid');

        $this->get(RewardResource::getUrl('view', ['record' => $acReward]))
            ->assertOk()
            ->assertSeeText('Account Credit Total')
            ->assertSeeText('£60.00')
            ->assertSeeText('Current member preference')
            ->assertSeeText('Bank Transfer');

        $bankReward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'status' => 'approved',
            'approved_at' => now(),
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::BankTransfer,
            'origin' => 'milestone_claim',
            'milestone_index' => 2,
        ]);

        Livewire::test(ViewReward::class, ['record' => $bankReward->id])
            ->assertActionHidden('applyAccountCredit')
            ->assertActionVisible('markPaid');
    }
}
