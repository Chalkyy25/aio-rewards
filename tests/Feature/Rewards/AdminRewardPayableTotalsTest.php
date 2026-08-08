<?php

namespace Tests\Feature\Rewards;

use App\Enums\PayoutMethod;
use App\Enums\Role;
use App\Filament\Widgets\RewardsOverviewWidget;
use App\Models\AmbassadorProfile;
use App\Models\Reward;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRewardPayableTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole(Role::SuperAdmin->value);

        $member = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->profile = AmbassadorProfile::factory()->for($member)->create();
    }

    private function paidReward(array $attrs): Reward
    {
        return Reward::factory()->create(array_merge([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'status' => 'paid',
            'currency' => 'gbp',
            'paid_at' => now()->startOfMonth()->addDays(2),
            'approved_at' => now()->startOfMonth(),
        ], $attrs));
    }

    public function test_sum_admin_payable_includes_account_credit_bonus(): void
    {
        $reward = $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'payment_method' => PayoutMethod::AccountCredit->value,
        ]);

        $this->assertSame(6000, $reward->adminPayableAmountMinor());
        $this->assertSame(6000, Reward::sumAdminPayableMinor(
            Reward::query()->where('status', 'paid')
        ));
    }

    public function test_sum_admin_payable_bank_transfer_is_cash_only(): void
    {
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::BankTransfer,
            'payment_method' => PayoutMethod::BankTransfer->value,
        ]);

        $this->assertSame(5000, Reward::sumAdminPayableMinor(
            Reward::query()->where('status', 'paid')
        ));
    }

    public function test_sum_admin_payable_legacy_null_snapshot_is_cash_only(): void
    {
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => null,
            'payment_method' => PayoutMethod::AccountCredit->value,
        ]);

        $this->assertSame(5000, Reward::sumAdminPayableMinor(
            Reward::query()->where('status', 'paid')
        ));
    }

    public function test_mixed_account_credit_and_bank_totals_sum_correctly(): void
    {
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'payment_method' => PayoutMethod::AccountCredit->value,
            'milestone_index' => 5,
        ]);
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::BankTransfer,
            'payment_method' => PayoutMethod::BankTransfer->value,
            'milestone_index' => 10,
        ]);
        $this->paidReward([
            'amount_minor' => 11000,
            'account_credit_bonus_minor_snapshot' => 2000,
            'preferred_payout_method_snapshot' => null,
            'milestone_index' => 15,
        ]);

        // AC £60 + bank £50 + legacy £110 = £220
        $this->assertSame(22000, Reward::sumAdminPayableMinor(
            Reward::query()->where('status', 'paid')
        ));
    }

    public function test_dashboard_paid_totals_use_payable_value_and_month_filter(): void
    {
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'payment_method' => PayoutMethod::AccountCredit->value,
            'paid_at' => now()->startOfMonth()->addDay(),
            'milestone_index' => 5,
        ]);
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::BankTransfer,
            'payment_method' => PayoutMethod::BankTransfer->value,
            'paid_at' => now()->startOfMonth()->addDays(3),
            'milestone_index' => 10,
        ]);
        // Outside rolling calendar month — must not appear in "Paid this month".
        $this->paidReward([
            'amount_minor' => 11000,
            'account_credit_bonus_minor_snapshot' => 2000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'payment_method' => PayoutMethod::AccountCredit->value,
            'paid_at' => now()->subMonth()->startOfMonth()->addDay(),
            'milestone_index' => 15,
        ]);

        $this->actingAs($this->admin);
        Livewire::test(RewardsOverviewWidget::class)
            ->assertOk()
            ->assertSeeText('Paid this month')
            ->assertSeeText('£110.00') // AC £60 + bank £50 this month
            ->assertSeeText('Total rewards paid')
            ->assertSeeText('£240.00'); // + prior-month AC £130
    }

    public function test_cash_only_sum_of_amount_minor_remains_available_separately(): void
    {
        $this->paidReward([
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 1000,
            'preferred_payout_method_snapshot' => PayoutMethod::AccountCredit,
            'payment_method' => PayoutMethod::AccountCredit->value,
        ]);

        $cashOnly = (int) Reward::query()->where('status', 'paid')->sum('amount_minor');
        $payable = Reward::sumAdminPayableMinor(Reward::query()->where('status', 'paid'));

        $this->assertSame(5000, $cashOnly);
        $this->assertSame(6000, $payable);
        $this->assertNotSame($cashOnly, $payable);
    }
}
