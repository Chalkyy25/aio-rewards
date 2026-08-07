<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every member-facing milestone page and asserts:
 *  - visual states (locked / current / available / paid),
 *  - access control (auth, verified, own-data-only),
 *  - tier data flows from configuration, not hard-coded Blade values,
 *  - no buyer PII on My Referrals.
 */
class MemberMilestonePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->profile = AmbassadorProfile::factory()->for($this->user)->create();
    }

    private function approveConversions(int $n): void
    {
        $svc = app(ConversionService::class);
        for ($i = 1; $i <= $n; $i++) {
            $pkg = Package::factory()->create(['name' => 'AIO Media Annual']);
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

    public function test_milestones_page_renders_locked_state_at_zero(): void
    {
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSeeText('Reward Milestones');
        $res->assertSee('data-testid="tier-card-5"', false);
        $res->assertSee('data-testid="tier-card-10"', false);
        $res->assertSee('data-testid="tier-card-more-coming"', false);
    }

    public function test_milestones_page_shows_current_state_below_threshold(): void
    {
        $this->approveConversions(3);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSeeText('3 of 5 approved referrals');
        $res->assertSeeText('2 more');
    }

    public function test_milestones_page_shows_50_available_state(): void
    {
        $this->approveConversions(5);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSee('data-testid="claim-cta-5"', false);
        $res->assertSeeText('£50 is available to claim');
        $res->assertSeeText('Save & Grow bonus');
    }

    public function test_milestones_page_shows_building_toward_110_state(): void
    {
        $this->approveConversions(7);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        // £50 still available while building toward £110.
        $res->assertSee('data-testid="claim-cta-5"', false);
        $res->assertSeeText('7 / 10 referrals');
    }

    public function test_milestones_page_shows_110_available_state(): void
    {
        $this->approveConversions(10);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSee('data-testid="claim-cta-10"', false);
        $res->assertSeeText('£110 is available to claim');
        // At 10, the £50 tier CTA is superseded — not rendered as available.
        $res->assertDontSee('data-testid="claim-cta-5"', false);
    }

    public function test_milestones_ui_reads_amounts_from_tier_configuration(): void
    {
        // Change a tier's amount and confirm the page reflects it (proves values are not hard-coded).
        RewardMilestoneTier::query()->where('threshold', 5)->update([
            'total_reward_amount_minor' => 7500,
            'title' => '£75 Reward',
        ]);
        $this->approveConversions(5);
        $res = $this->actingAs($this->user)->get('/ambassador/rewards/milestones')->assertOk();
        $res->assertSeeText('£75 is available to claim');
        $res->assertSeeText('£75 Reward');
    }

    public function test_claim_action_creates_reward_and_redirects(): void
    {
        $this->approveConversions(5);
        $tier = RewardMilestoneTier::query()->where('threshold', 5)->first();

        $this->actingAs($this->user)
            ->post("/ambassador/rewards/milestones/{$tier->id}/claim", [
                'idempotency_key' => 'client-key-42',
            ])
            ->assertRedirect('/ambassador/rewards/milestones');

        $this->assertSame(1, Reward::query()->where('ambassador_profile_id', $this->profile->id)->count());
    }

    public function test_reward_history_shows_only_current_member(): void
    {
        $mine = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'origin' => 'milestone_claim',
            'amount_minor' => 5000,
            'status' => 'paid',
            'milestone_index' => 5,
            'paid_at' => now(),
        ]);

        $otherUser = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $otherProfile = AmbassadorProfile::factory()->for($otherUser)->create();
        Reward::factory()->create([
            'ambassador_profile_id' => $otherProfile->id,
            'origin' => 'milestone_claim',
            'amount_minor' => 11000,
            'status' => 'paid',
            'milestone_index' => 10,
            'paid_at' => now(),
            'note' => 'CONFIDENTIAL',
        ]);

        $res = $this->actingAs($this->user)->get('/ambassador/rewards/history')->assertOk();
        $res->assertSee('history-card-'.$mine->id, false);
        $res->assertDontSee('CONFIDENTIAL');
        $res->assertDontSeeText('£110.00');
    }

    public function test_my_referrals_page_hides_buyer_pii(): void
    {
        $pkg = Package::factory()->create(['name' => 'AIO Media Annual']);
        $purchase = Purchase::factory()->create([
            'package_id' => $pkg->id,
            'status' => 'paid',
            'buyer_email' => 'buyer@example.test',
            'buyer_name' => 'Sensitive Buyer',
        ]);
        ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
            'status' => 'approved',
            'amount_minor' => 5000,
            'currency' => 'gbp',
            'approved_at' => now(),
        ]);

        $res = $this->actingAs($this->user)->get('/ambassador/referrals')->assertOk();
        $res->assertSee('AIO Media Annual');
        $res->assertDontSee('buyer@example.test');
        $res->assertDontSee('Sensitive Buyer');
    }

    public function test_unverified_user_is_blocked_from_all_pages(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null, 'is_active' => true]);
        AmbassadorProfile::factory()->for($unverified)->create();

        $this->actingAs($unverified)->get('/ambassador/rewards/milestones')->assertRedirect('/email/verify');
        $this->actingAs($unverified)->get('/ambassador/rewards/history')->assertRedirect('/email/verify');
        $this->actingAs($unverified)->get('/ambassador/referrals')->assertRedirect('/email/verify');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/ambassador/rewards/milestones')->assertRedirect('/login');
    }

    public function test_regular_member_cannot_access_admin(): void
    {
        $this->actingAs($this->user)->get('/admin')->assertStatus(403);
    }
}
