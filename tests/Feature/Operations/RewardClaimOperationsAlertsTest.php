<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\OperationsScanner;
use App\Domain\Rewards\RewardsEngine;
use App\Domain\Settings\SettingsRepository;
use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Enums\OperationsType;
use App\Models\AmbassadorProfile;
use App\Models\OperationsItem;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\RewardRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reward Claim Operations Alerts — stale pending claims and approved-unpaid
 * payouts. Extends the existing Operations Centre scanner; does not mutate
 * reward accounting itself.
 */
class RewardClaimOperationsAlertsTest extends TestCase
{
    use RefreshDatabase;

    private AmbassadorProfile $profile;

    private User $member;

    private RewardMilestoneTier $tier;

    private RewardRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->member = User::factory()->create([
            'name' => 'Casey Member',
            'email' => 'casey.member@example.com',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->profile = AmbassadorProfile::factory()->for($this->member)->create();
        $this->tier = RewardMilestoneTier::query()->where('threshold', 5)->firstOrFail();
        $this->rule = RewardRule::factory()->create([
            'name' => 'Legacy inactive',
            'is_active' => false,
            'amount_minor' => 5000,
        ]);
    }

    public function test_pending_reward_under_threshold_creates_no_operations_item(): void
    {
        $this->makePendingClaim(claimedDaysAgo: 3);

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseMissing('operations_items', [
            'type' => OperationsType::RewardAwaitingApproval->value,
        ]);
    }

    public function test_pending_reward_at_or_over_threshold_creates_one_medium_item(): void
    {
        $reward = $this->makePendingClaim(claimedDaysAgo: 7);

        app(OperationsScanner::class)->scan();

        $item = OperationsItem::query()
            ->where('type', OperationsType::RewardAwaitingApproval->value)
            ->where('subject_id', $reward->id)
            ->first();

        $this->assertNotNull($item);
        $this->assertSame(OperationsPriority::Medium->value, $item->priority);
        $this->assertSame('reward-claim-awaiting-approval:'.$reward->id, $item->dedupe_key);
        $this->assertSame(OperationsStatus::New->value, $item->status);
    }

    public function test_repeated_scans_do_not_duplicate_pending_approval_item(): void
    {
        $reward = $this->makePendingClaim(claimedDaysAgo: 10);

        app(OperationsScanner::class)->scan();
        app(OperationsScanner::class)->scan();
        app(OperationsScanner::class)->scan();

        $this->assertSame(1, OperationsItem::query()
            ->where('dedupe_key', 'reward-claim-awaiting-approval:'.$reward->id)
            ->count());
    }

    public function test_approval_auto_resolves_pending_approval_item(): void
    {
        $reward = $this->makePendingClaim(claimedDaysAgo: 8);
        app(OperationsScanner::class)->scan();

        app(RewardsEngine::class)->approve($reward);
        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'dedupe_key' => 'reward-claim-awaiting-approval:'.$reward->id,
            'status' => OperationsStatus::Resolved->value,
        ]);
        $this->assertDatabaseHas('operations_item_events', [
            'action' => 'auto_resolved',
        ]);
    }

    public function test_rejection_auto_resolves_pending_approval_item(): void
    {
        $reward = $this->makePendingClaim(claimedDaysAgo: 8);
        app(OperationsScanner::class)->scan();

        app(RewardsEngine::class)->reject($reward, note: 'fraud check');
        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'dedupe_key' => 'reward-claim-awaiting-approval:'.$reward->id,
            'status' => OperationsStatus::Resolved->value,
        ]);
    }

    public function test_reversal_auto_resolves_pending_approval_item(): void
    {
        $reward = $this->makePendingClaim(claimedDaysAgo: 8);
        app(OperationsScanner::class)->scan();

        // Reverse is paid-only; pending claims use reject.
        app(RewardsEngine::class)->reject($reward, note: 'chargeback');
        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'dedupe_key' => 'reward-claim-awaiting-approval:'.$reward->id,
            'status' => OperationsStatus::Resolved->value,
        ]);
    }

    public function test_approved_reward_under_unpaid_threshold_creates_no_item(): void
    {
        $this->makeApprovedUnpaid(approvedHoursAgo: 24);

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseMissing('operations_items', [
            'type' => OperationsType::RewardApprovedAwaitingPayment->value,
        ]);
    }

    public function test_approved_reward_at_or_over_threshold_creates_high_priority_item(): void
    {
        $reward = $this->makeApprovedUnpaid(approvedHoursAgo: 72);

        app(OperationsScanner::class)->scan();

        $item = OperationsItem::query()
            ->where('type', OperationsType::RewardApprovedAwaitingPayment->value)
            ->where('subject_id', $reward->id)
            ->first();

        $this->assertNotNull($item);
        $this->assertSame(OperationsPriority::High->value, $item->priority);
        $this->assertSame('reward-approved-awaiting-payment:'.$reward->id, $item->dedupe_key);
    }

    public function test_repeated_scans_do_not_duplicate_unpaid_item(): void
    {
        $reward = $this->makeApprovedUnpaid(approvedHoursAgo: 100);

        app(OperationsScanner::class)->scan();
        app(OperationsScanner::class)->scan();

        $this->assertSame(1, OperationsItem::query()
            ->where('dedupe_key', 'reward-approved-awaiting-payment:'.$reward->id)
            ->count());
    }

    public function test_mark_paid_auto_resolves_unpaid_item(): void
    {
        $reward = $this->makeApprovedUnpaid(approvedHoursAgo: 80);
        app(OperationsScanner::class)->scan();

        app(RewardsEngine::class)->markPaid($reward);
        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'dedupe_key' => 'reward-approved-awaiting-payment:'.$reward->id,
            'status' => OperationsStatus::Resolved->value,
        ]);
    }

    public function test_reversal_auto_resolves_unpaid_item(): void
    {
        $reward = $this->makeApprovedUnpaid(approvedHoursAgo: 80);
        app(OperationsScanner::class)->scan();

        app(RewardsEngine::class)->reject($reward, note: 'reversed after approval');
        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'dedupe_key' => 'reward-approved-awaiting-payment:'.$reward->id,
            'status' => OperationsStatus::Resolved->value,
        ]);
    }

    public function test_settings_thresholds_are_honoured(): void
    {
        settings()->putMany([
            'ops.reward.claim_awaiting_approval_days' => '3',
            'ops.reward.approved_unpaid_hours' => '12',
        ]);

        $freshClaim = $this->makePendingClaim(claimedDaysAgo: 2);
        $staleClaim = $this->makePendingClaim(claimedDaysAgo: 3, milestoneIndex: 2);
        $freshApproved = $this->makeApprovedUnpaid(approvedHoursAgo: 6, milestoneIndex: 3);
        $staleApproved = $this->makeApprovedUnpaid(approvedHoursAgo: 12, milestoneIndex: 4);

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseMissing('operations_items', [
            'type' => OperationsType::RewardAwaitingApproval->value,
            'subject_id' => $freshClaim->id,
        ]);
        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::RewardAwaitingApproval->value,
            'subject_id' => $staleClaim->id,
        ]);
        $this->assertDatabaseMissing('operations_items', [
            'type' => OperationsType::RewardApprovedAwaitingPayment->value,
            'subject_id' => $freshApproved->id,
        ]);
        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::RewardApprovedAwaitingPayment->value,
            'subject_id' => $staleApproved->id,
        ]);
    }

    public function test_member_and_reward_links_resolve_in_meta(): void
    {
        $reward = $this->makePendingClaim(claimedDaysAgo: 9);
        app(OperationsScanner::class)->scan();

        $item = OperationsItem::query()
            ->where('dedupe_key', 'reward-claim-awaiting-approval:'.$reward->id)
            ->firstOrFail();
        $meta = (array) $item->meta;

        $this->assertSame('Casey Member', $meta['member_name']);
        $this->assertSame($reward->id, $meta['reward_id']);
        $this->assertSame('/admin/rewards/'.$reward->id, $meta['reward_admin_path']);
        $this->assertSame('/admin/ambassadors/'.$this->profile->id, $meta['member_admin_path']);
        $this->assertSame(5, $meta['milestone_threshold']);
        $this->assertSame('£50.00', $meta['amount_formatted']);
    }

    public function test_operations_meta_excludes_buyer_pii_and_secrets(): void
    {
        $tier = RewardMilestoneTier::query()->where('threshold', 10)->firstOrFail();
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'reward_rule_id' => $this->rule->id,
            'milestone_tier_id' => $tier->id,
            'milestone_index' => 10,
            'origin' => 'legacy_rule',
            'amount_minor' => 11000,
            'status' => 'pending_approval',
            'tier_snapshot' => $tier->snapshot(),
            'note' => 'should not leak secrets',
            'idempotency_key' => 'test-pii-check-'.uniqid(),
        ]);
        $reward->forceFill(['created_at' => now()->subDays(14)])->save();

        app(OperationsScanner::class)->scan();

        $item = OperationsItem::query()
            ->where('dedupe_key', 'reward-claim-awaiting-approval:'.$reward->id)
            ->firstOrFail();
        $meta = (array) $item->meta;
        $encoded = json_encode($meta);

        $forbidden = [
            'password', 'provider_password', 'buyer_email', 'stripe_secret',
            'api_key', 'webhook_secret', 'credit_card', 'card_number',
            'letmein', 'sk_live', 'whsec_',
        ];
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, strtolower((string) $encoded), "meta leaked: {$needle}");
        }

        // Safe fields present
        $this->assertArrayHasKey('member_name', $meta);
        $this->assertArrayHasKey('reward_admin_path', $meta);
        $this->assertSame(1000, $meta['bonus_amount_minor']);
        $this->assertArrayNotHasKey('note', $meta);
        $this->assertArrayNotHasKey('provider_username', $meta);
        $this->assertArrayNotHasKey('email', $meta);
    }

    public function test_settings_schema_includes_reward_ops_thresholds(): void
    {
        $schema = app(SettingsRepository::class)->schema();

        $this->assertArrayHasKey('ops.reward.claim_awaiting_approval_days', $schema);
        $this->assertSame('7', $schema['ops.reward.claim_awaiting_approval_days']['default']);
        $this->assertArrayHasKey('ops.reward.approved_unpaid_hours', $schema);
        $this->assertSame('72', $schema['ops.reward.approved_unpaid_hours']['default']);
        $this->assertTrue($schema['ops.reward.claim_awaiting_approval_days']['integer'] ?? false);
        $this->assertSame(1, $schema['ops.reward.claim_awaiting_approval_days']['min']);
        $this->assertSame(90, $schema['ops.reward.claim_awaiting_approval_days']['max']);
    }

    private function makePendingClaim(int $claimedDaysAgo, int $milestoneIndex = 1): Reward
    {
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'reward_rule_id' => $this->rule->id,
            'milestone_tier_id' => $this->tier->id,
            'milestone_index' => $milestoneIndex,
            'cycle_number' => $milestoneIndex,
            'origin' => 'legacy_rule',
            'amount_minor' => 5000,
            'status' => 'pending_approval',
            'tier_snapshot' => $this->tier->snapshot(),
            'idempotency_key' => 'test-claim-'.$milestoneIndex.'-'.uniqid(),
        ]);
        $reward->forceFill(['created_at' => now()->subDays($claimedDaysAgo)])->save();

        return $reward->refresh();
    }

    private function makeApprovedUnpaid(int $approvedHoursAgo, int $milestoneIndex = 10): Reward
    {
        $reward = Reward::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'reward_rule_id' => $this->rule->id,
            'milestone_tier_id' => $this->tier->id,
            'milestone_index' => $milestoneIndex,
            'cycle_number' => $milestoneIndex,
            'origin' => 'legacy_rule',
            'amount_minor' => 5000,
            'status' => 'approved',
            'approved_at' => now()->subHours($approvedHoursAgo),
            'paid_at' => null,
            'tier_snapshot' => $this->tier->snapshot(),
            'idempotency_key' => 'test-approved-'.$milestoneIndex.'-'.uniqid(),
        ]);
        $reward->forceFill(['created_at' => now()->subDays(20)])->save();

        return $reward->refresh();
    }
}
