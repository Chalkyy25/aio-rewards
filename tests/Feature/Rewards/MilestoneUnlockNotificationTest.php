<?php

namespace Tests\Feature\Rewards;

use App\Domain\Referrals\ConversionService;
use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Domain\Rewards\MilestoneUnlockNotifier;
use App\Domain\Settings\SettingsRepository;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\MilestoneUnlockNotification;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use App\Notifications\MilestoneUnlockedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MilestoneUnlockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create([
            'name' => 'Ashley Tester',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->profile = AmbassadorProfile::factory()->for($this->user)->create([
            'flagged_for_review' => false,
        ]);
        MemberPayoutProfile::factory()->forProfile($this->profile)->accountCredit()->create();
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
                'buyer_email' => 'buyer-secret-'.$i.'@example.com',
                'buyer_name' => 'Secret Buyer '.$i,
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

    private function createPendingConversion(): ReferralConversion
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'completed',
            'paid_at' => now()->subDays(20),
            'ambassador_profile_id_snapshot' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
            'buyer_email' => 'pending-buyer@example.com',
            'buyer_name' => 'Pending Buyer',
        ]);

        return ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
            'status' => 'pending',
            'amount_minor' => $purchase->amount_minor,
            'currency' => 'gbp',
            'pending_until' => now()->subDay(),
        ]);
    }

    private function tier(int $threshold): RewardMilestoneTier
    {
        return RewardMilestoneTier::query()->where('threshold', $threshold)->firstOrFail();
    }

    public function test_four_approved_referrals_do_not_notify(): void
    {
        Notification::fake();

        $this->approveConversions(4);

        Notification::assertNotSentTo($this->user, MilestoneUnlockedNotification::class);
        $this->assertSame(0, MilestoneUnlockNotification::count());
    }

    public function test_fifth_approved_qualifying_referral_sends_unlock_notification(): void
    {
        Notification::fake();

        $this->approveConversions(5);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            return $n->snapshot->threshold === 5
                && $n->snapshot->totalRewardAmountMinor === 5000
                && $n->snapshot->cycleNumber === 1;
        });

        $this->assertSame(1, MilestoneUnlockNotification::count());
        $row = MilestoneUnlockNotification::first();
        $this->assertSame(
            MilestoneUnlockNotification::buildKey($this->profile->id, 1, $this->tier(5)->id),
            $row->idempotency_key
        );
    }

    public function test_same_event_replayed_does_not_duplicate_notification(): void
    {
        Notification::fake();

        $this->approveConversions(5);
        $conv = ReferralConversion::query()->latest('id')->firstOrFail();

        // Replay the domain event as an approval sweep / retry would.
        ReferralConversionApproved::dispatch($conv, true);
        app(MilestoneUnlockNotifier::class)->evaluate($this->profile->fresh());

        Notification::assertSentToTimes($this->user, MilestoneUnlockedNotification::class, 1);
        $this->assertSame(1, MilestoneUnlockNotification::count());
    }

    public function test_queue_retry_does_not_create_duplicate_logical_unlock(): void
    {
        Notification::fake();

        $this->approveConversions(5);
        $row = MilestoneUnlockNotification::firstOrFail();
        $this->assertContains($row->status, [
            MilestoneUnlockNotification::STATUS_QUEUED,
            MilestoneUnlockNotification::STATUS_SENT,
        ]);

        // Simulate a Horizon retry path: evaluate again while marker is queued/sent.
        $again = app(MilestoneUnlockNotifier::class)->evaluate($this->profile->fresh());
        $this->assertNull($again);

        Notification::assertSentToTimes($this->user, MilestoneUnlockedNotification::class, 1);
        $this->assertSame(1, MilestoneUnlockNotification::count());
    }

    public function test_fifth_referral_still_pending_does_not_notify(): void
    {
        Notification::fake();

        $this->approveConversions(4);
        $this->createPendingConversion(); // 5th stays pending

        Notification::assertNotSentTo($this->user, MilestoneUnlockedNotification::class);
        $this->assertSame(0, MilestoneUnlockNotification::count());
    }

    public function test_rejected_or_reversed_referral_does_not_notify(): void
    {
        Notification::fake();

        $this->approveConversions(4);
        $conv = $this->createPendingConversion();
        app(ConversionService::class)->reverse($conv, 'chargeback');

        Notification::assertNotSentTo($this->user, MilestoneUnlockedNotification::class);
        $this->assertSame(0, MilestoneUnlockNotification::count());
    }

    public function test_new_cycle_after_claim_can_unlock_five_again(): void
    {
        Notification::fake();

        $this->approveConversions(5);
        Notification::assertSentToTimes($this->user, MilestoneUnlockedNotification::class, 1);

        app(MilestoneProgressionService::class)->claim(
            $this->profile,
            $this->tier(5),
            $this->user,
        );

        $this->approveConversions(5); // new cycle

        Notification::assertSentToTimes($this->user, MilestoneUnlockedNotification::class, 2);
        $this->assertSame(2, MilestoneUnlockNotification::count());

        $keys = MilestoneUnlockNotification::query()->pluck('idempotency_key')->all();
        $this->assertContains(
            MilestoneUnlockNotification::buildKey($this->profile->id, 1, $this->tier(5)->id),
            $keys
        );
        $this->assertContains(
            MilestoneUnlockNotification::buildKey($this->profile->id, 2, $this->tier(5)->id),
            $keys
        );
    }

    public function test_member_who_does_not_claim_at_five_receives_ten_unlock(): void
    {
        Notification::fake();

        $this->approveConversions(10);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            return $n->snapshot->threshold === 10
                && $n->snapshot->totalRewardAmountMinor === 11000;
        });

        $thresholds = collect(Notification::sent($this->user, MilestoneUnlockedNotification::class))
            ->map(fn (MilestoneUnlockedNotification $n) => $n->snapshot->threshold)
            ->sort()
            ->values()
            ->all();
        $this->assertSame([5, 10], $thresholds);
    }

    public function test_ten_tier_notification_includes_configured_bonus(): void
    {
        Notification::fake();

        $this->approveConversions(10);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            if ($n->snapshot->threshold !== 10) {
                return false;
            }
            $this->assertSame(1000, $n->snapshot->bonusAmountMinor);
            $mail = $n->toMail($this->user);
            $rendered = implode(' ', $mail->introLines);

            return str_contains($rendered, 'Save & Grow')
                && str_contains($rendered, '£110');
        });
    }

    public function test_future_active_claimable_tier_notifies_without_hardcoded_threshold(): void
    {
        Notification::fake();

        // Use configured 15-referral tier (seeded by ladder extension migration).
        $this->approveConversions(15);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            return $n->snapshot->threshold === 15
                && $n->snapshot->totalRewardAmountMinor === 17000
                && $n->snapshot->bonusAmountMinor === 2000;
        });
    }

    public function test_inactive_tier_does_not_notify(): void
    {
        Notification::fake();

        $this->tier(5)->update(['is_active' => false]);
        // Unique active threshold constraint: deactivate carefully; 5 is unique with is_active.
        $this->approveConversions(5);

        Notification::assertNotSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            return $n->snapshot->threshold === 5;
        });
        // With 5 inactive, progress may surface 10 only at threshold 10 — at 5 eligible, nothing claimable.
        $this->assertSame(0, MilestoneUnlockNotification::count());
    }

    public function test_invisible_or_non_claimable_tier_does_not_notify(): void
    {
        Notification::fake();

        $this->tier(5)->update(['is_visible' => false, 'is_claimable' => false]);
        $this->approveConversions(5);

        Notification::assertNotSentTo($this->user, MilestoneUnlockedNotification::class);
        $this->assertSame(0, MilestoneUnlockNotification::count());
    }

    public function test_email_contains_dynamic_amount_and_threshold(): void
    {
        Notification::fake();

        $this->approveConversions(5);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            $mail = $n->toMail($this->user);
            $body = implode(' ', array_merge(
                [(string) $mail->subject, (string) $mail->greeting],
                $mail->introLines
            ));

            return str_contains((string) $mail->subject, '£50')
                && str_contains($body, '5 approved referrals')
                && str_contains($body, '£50')
                && str_contains($body, 'Hi Ashley');
        });
    }

    public function test_email_contains_no_buyer_pii(): void
    {
        Notification::fake();

        $this->approveConversions(5);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            $mail = $n->toMail($this->user);
            $payload = json_encode([
                'subject' => $mail->subject,
                'intro' => $mail->introLines,
                'action' => $mail->actionUrl,
                'array' => $n->toArray($this->user),
                'snapshot' => $n->snapshot->toArray(),
            ]);

            $this->assertIsString($payload);
            $this->assertStringNotContainsString('buyer-secret', $payload);
            $this->assertStringNotContainsString('Secret Buyer', $payload);
            $this->assertStringNotContainsString('pending-buyer@example.com', $payload);

            return true;
        });
    }

    public function test_cta_uses_named_aio_rewards_route(): void
    {
        Notification::fake();

        $this->approveConversions(5);

        Notification::assertSentTo($this->user, MilestoneUnlockedNotification::class, function (MilestoneUnlockedNotification $n) {
            $mail = $n->toMail($this->user);
            $expected = route('ambassador.milestones');

            return $mail->actionUrl === $expected
                && $mail->actionText === 'View My Rewards'
                && ($n->toArray($this->user)['rewards_url'] ?? null) === $expected;
        });
    }

    public function test_setting_disabled_prevents_send(): void
    {
        Notification::fake();

        app(SettingsRepository::class)->put('notifications.milestone_unlock_enabled', '0');

        $this->approveConversions(5);

        Notification::assertNotSentTo($this->user, MilestoneUnlockedNotification::class);
        $this->assertSame(0, MilestoneUnlockNotification::count());
    }

    public function test_failed_marker_can_be_retried_without_duplicate_rows(): void
    {
        Notification::fake();

        $this->approveConversions(5);
        $row = MilestoneUnlockNotification::firstOrFail();
        $row->update([
            'status' => MilestoneUnlockNotification::STATUS_FAILED,
            'failure_class' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        $retried = app(MilestoneUnlockNotifier::class)->evaluate($this->profile->fresh());
        $this->assertNotNull($retried);
        $this->assertSame(1, MilestoneUnlockNotification::count());
        Notification::assertSentToTimes($this->user, MilestoneUnlockedNotification::class, 2);
    }
}
