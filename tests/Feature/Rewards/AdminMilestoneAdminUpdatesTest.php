<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Enums\Role;
use App\Filament\Resources\AmbassadorResource;
use App\Filament\Resources\ReferralAllocationResource;
use App\Filament\Resources\ReferralAllocationResource\Pages\ListReferralAllocations;
use App\Filament\Resources\RewardMilestoneTierResource\Pages\CreateRewardMilestoneTier;
use App\Filament\Resources\RewardResource;
use App\Filament\Resources\RewardResource\Pages\ListRewards;
use App\Filament\Widgets\MilestoneProgressionWidget;
use App\Filament\Widgets\RewardsOverviewWidget;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin-side Filament coverage for the new Reward Milestone system.
 */
class AdminMilestoneAdminUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole(Role::SuperAdmin->value);

        $this->member = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->profile = AmbassadorProfile::factory()->for($this->member)->create();
        MemberPayoutProfile::factory()->forProfile($this->profile)->accountCredit()->create();
    }

    private function approveConversions(int $n): void
    {
        $svc = app(ConversionService::class);
        for ($i = 1; $i <= $n; $i++) {
            $pkg = Package::factory()->create();
            $purchase = Purchase::factory()->create([
                'package_id' => $pkg->id,
                'status' => 'paid', 'fulfilment_status' => 'completed',
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
    }

    // ---- Widgets --------------------------------------------------------

    public function test_rewards_overview_widget_shows_reward_lifecycle_counts(): void
    {
        // Seed rewards: one pending, one approved, one paid £50 this month.
        Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'amount_minor' => 5000, 'status' => 'pending_approval', 'milestone_index' => 5,
        ]);
        Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'amount_minor' => 11000, 'status' => 'approved', 'milestone_index' => 10,
        ]);
        Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'amount_minor' => 5000, 'status' => 'paid', 'milestone_index' => 5,
            'paid_at' => now()->startOfMonth()->addDays(1),
        ]);

        $this->actingAs($this->admin);
        $res = Livewire::test(RewardsOverviewWidget::class)->assertOk();
        $res->assertSeeText('Claims awaiting approval');
        $res->assertSeeText('Claims overdue for approval');
        $res->assertSeeText('Awaiting payment');
        $res->assertSeeText('Approved rewards overdue for payment');
        $res->assertSeeText('Paid this month');
        $res->assertSeeText('£50.00'); // paid this month
        $res->assertSeeText('Total rewards paid');
        $res->assertSeeText('Allocated referrals');
    }

    public function test_milestone_progression_widget_counts_members_at_each_tier(): void
    {
        // Member A: 3 approved — below all thresholds.
        // Member B: 7 approved — at £50 tier.
        // Member C: 11 approved — at £110 tier.
        $this->approveConversions(3);

        $userB = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $profileB = AmbassadorProfile::factory()->for($userB)->create();
        $userC = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $profileC = AmbassadorProfile::factory()->for($userC)->create();

        $seed = function (AmbassadorProfile $p, int $n) {
            $svc = app(ConversionService::class);
            for ($i = 0; $i < $n; $i++) {
                $pkg = Package::factory()->create();
                $purchase = Purchase::factory()->create([
                    'package_id' => $pkg->id,
                    'status' => 'paid', 'fulfilment_status' => 'completed',
                    'paid_at' => now()->subDays(20),
                    'ambassador_profile_id_snapshot' => $p->id,
                    'referral_code_snapshot' => $p->referral_code,
                ]);
                $c = ReferralConversion::create([
                    'purchase_id' => $purchase->id,
                    'ambassador_profile_id' => $p->id,
                    'referral_code_snapshot' => $p->referral_code,
                    'status' => 'pending',
                    'amount_minor' => $purchase->amount_minor,
                    'currency' => 'gbp',
                    'pending_until' => now()->subDay(),
                ]);
                $svc->approve($c);
            }
        };
        $seed($profileB, 7);
        $seed($profileC, 11);

        $this->actingAs($this->admin);
        $res = Livewire::test(MilestoneProgressionWidget::class)->assertOk();
        $res->assertSeeText('Members progressing');
        $res->assertSeeText('Members at 5+ unclaimed');
        $res->assertSeeText('Members at 10+ unclaimed');
        // Sanity: the widget returned real numeric counts (verifiable via internal state).
        $stats = (fn () => $this->getStats())->call(new MilestoneProgressionWidget);
        $labels = collect($stats)->mapWithKeys(fn ($s) => [
            (fn () => $this->label)->call($s) => (fn () => $this->value)->call($s),
        ]);
        $this->assertSame('2', $labels['Members at 5+ unclaimed']);
        $this->assertSame('1', $labels['Members at 10+ unclaimed']);
    }

    // ---- Referral Allocation resource ----------------------------------

    public function test_allocation_resource_is_read_only(): void
    {
        $this->assertFalse(ReferralAllocationResource::canCreate());
        $random = new ReferralAllocation;
        $this->assertFalse(ReferralAllocationResource::canEdit($random));
        $this->assertFalse(ReferralAllocationResource::canDelete($random));
    }

    public function test_admin_can_list_referral_allocations(): void
    {
        $this->approveConversions(5);
        app(MilestoneProgressionService::class)->claim(
            $this->profile, RewardMilestoneTier::where('threshold', 5)->first(), $this->admin
        );

        $this->actingAs($this->admin);
        Livewire::test(ListReferralAllocations::class)
            ->assertOk()
            ->assertCanSeeTableRecords(ReferralAllocation::all());
    }

    // ---- Ambassador infolist reward progress ---------------------------

    public function test_ambassador_view_shows_reward_progress_section(): void
    {
        $this->approveConversions(7);
        $this->actingAs($this->admin);

        $res = $this->get(AmbassadorResource::getUrl('view', ['record' => $this->profile]))->assertOk();
        $res->assertSeeText('Reward progress');
        $res->assertSeeText('Active cycle referrals');
        $res->assertSeeText('Available now');
        $res->assertSeeText('£50.00');
        $res->assertSeeText('Save & Grow bonus building');
        $res->assertSeeText('Next milestone');
    }

    // ---- RewardResource enrichments ------------------------------------

    public function test_reward_view_shows_milestone_tier_and_allocated_referrals(): void
    {
        $this->approveConversions(10);
        $reward = app(MilestoneProgressionService::class)->claim(
            $this->profile, RewardMilestoneTier::where('threshold', 10)->first(), $this->admin
        );

        $this->actingAs($this->admin);
        $res = $this->get(RewardResource::getUrl('view', ['record' => $reward]))->assertOk();
        $res->assertSeeText('£110 Reward');    // tier title
        $res->assertSeeText('Milestone claim'); // origin
        $res->assertSeeText('Save & Grow bonus (config)');
        $res->assertSeeText('Funding referrals');
    }

    // ---- Tier form validation -----------------------------------------

    public function test_admin_cannot_create_tier_where_bonus_exceeds_total(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateRewardMilestoneTier::class)
            ->fillForm([
                'title' => 'Bad',
                'threshold' => 30,
                'total_reward_amount_minor' => 1000,
                'bonus_amount_minor' => 5000,
                'currency' => 'gbp',
                'is_active' => true, 'is_visible' => true, 'is_claimable' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['bonus_amount_minor']);
    }

    // ---- Domain-only mutations ----------------------------------------

    public function test_reject_from_filament_reward_table_uses_domain_service(): void
    {
        $this->approveConversions(5);
        $reward = app(MilestoneProgressionService::class)->claim(
            $this->profile, RewardMilestoneTier::where('threshold', 5)->first(), $this->admin
        );

        $this->actingAs($this->admin);
        Livewire::test(ListRewards::class)
            ->callTableAction('reject', $reward, ['note' => 'admin error'])
            ->assertHasNoTableActionErrors();

        $fresh = $reward->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('release', $fresh->reject_disposition);
        // Domain path released allocations — proving the action didn't
        // just mutate `rewards.status` directly but went through the
        // MilestoneProgressionService.
        $this->assertSame(0, ReferralAllocation::query()
            ->where('reward_id', $reward->id)->whereNotNull('active_marker')->count());
    }
}
