<?php

namespace Tests\Feature\Referrals;

use App\Domain\Referrals\ConversionService;
use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Jobs\ApproveRipeReferralConversionsJob;
use App\Models\AmbassadorProfile;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\User;
use App\Notifications\AmbassadorConversionApprovedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConversionApprovalSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['referrals.conversion.approval_window_days' => 14]);
    }

    /**
     * @param array{
     *   paid_days_ago?: int,
     *   payment_status?: string,
     *   fulfilment_status?: string,
     *   conversion_status?: string,
     *   flagged?: bool,
     *   user_active?: bool,
     * } $overrides
     * @return array{purchase: Purchase, conversion: ReferralConversion, profile: AmbassadorProfile}
     */
    private function scenario(array $overrides = []): array
    {
        $userActive = $overrides['user_active'] ?? true;
        $user = User::factory()->create(['is_active' => $userActive]);
        $profile = AmbassadorProfile::factory()->for($user)->create([
            'flagged_for_review' => $overrides['flagged'] ?? false,
        ]);
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => $overrides['payment_status'] ?? 'paid',
            'paid_at' => now()->subDays($overrides['paid_days_ago'] ?? 20),
            'fulfilment_status' => $overrides['fulfilment_status'] ?? 'completed',
            'ambassador_profile_id_snapshot' => $profile->id,
            'referral_code_snapshot' => $profile->referral_code,
        ]);
        $conversion = ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $profile->id,
            'referral_code_snapshot' => $profile->referral_code,
            'status' => $overrides['conversion_status'] ?? 'pending',
            'amount_minor' => $purchase->amount_minor,
            'currency' => 'gbp',
            'pending_until' => now()->subDays(($overrides['paid_days_ago'] ?? 20) - 14),
        ]);

        return compact('purchase', 'conversion', 'profile');
    }

    public function test_eligible_pending_conversion_is_auto_approved(): void
    {
        Event::fake([ReferralConversionApproved::class]);
        Notification::fake();

        ['conversion' => $conv] = $this->scenario();

        $result = app(ConversionService::class)->runApprovalSweep();
        $this->assertSame(['scanned' => 1, 'approved' => 1, 'skipped' => 0], $result);

        $conv->refresh();
        $this->assertSame('approved', $conv->status);
        $this->assertNotNull($conv->approved_at);

        Event::assertDispatched(
            ReferralConversionApproved::class,
            fn (ReferralConversionApproved $e) => $e->conversion->id === $conv->id && $e->auto === true,
        );
        Notification::assertSentTo($conv->ambassadorProfile->user, AmbassadorConversionApprovedNotification::class);
        $this->assertTrue(AuditLog::where('action', 'conversion.approved_auto')->exists());
        $this->assertTrue(AuditLog::where('action', 'conversion.sweep.completed')->exists());
    }

    public function test_conversion_still_inside_window_is_not_approved(): void
    {
        ['conversion' => $conv] = $this->scenario(['paid_days_ago' => 3]);

        $result = app(ConversionService::class)->runApprovalSweep();
        $this->assertSame(0, $result['approved']);
        $this->assertSame('pending', $conv->fresh()->status);
    }

    public function test_refunded_order_never_approves(): void
    {
        ['conversion' => $conv] = $this->scenario(['payment_status' => 'refunded']);

        $this->assertSame(0, app(ConversionService::class)->runApprovalSweep()['approved']);
        $this->assertSame('pending', $conv->fresh()->status);
    }

    public function test_chargeback_order_never_approves(): void
    {
        ['conversion' => $conv] = $this->scenario(['payment_status' => 'chargeback']);

        $this->assertSame(0, app(ConversionService::class)->runApprovalSweep()['approved']);
        $this->assertSame('pending', $conv->fresh()->status);
    }

    public function test_incomplete_fulfilment_never_approves(): void
    {
        ['conversion' => $conv] = $this->scenario(['fulfilment_status' => 'in_progress']);

        $this->assertSame(0, app(ConversionService::class)->runApprovalSweep()['approved']);
        $this->assertSame('pending', $conv->fresh()->status);
    }

    public function test_flagged_ambassador_is_skipped(): void
    {
        ['conversion' => $conv] = $this->scenario(['flagged' => true]);

        $this->assertSame(0, app(ConversionService::class)->runApprovalSweep()['approved']);
        $this->assertSame('pending', $conv->fresh()->status);
    }

    public function test_inactive_ambassador_user_is_skipped(): void
    {
        ['conversion' => $conv] = $this->scenario(['user_active' => false]);

        $this->assertSame(0, app(ConversionService::class)->runApprovalSweep()['approved']);
        $this->assertSame('pending', $conv->fresh()->status);
    }

    public function test_already_approved_conversion_is_not_double_processed(): void
    {
        Notification::fake();
        ['conversion' => $conv] = $this->scenario(['conversion_status' => 'approved']);

        $result = app(ConversionService::class)->runApprovalSweep();
        $this->assertSame(0, $result['approved']);
        Notification::assertNothingSent();
    }

    public function test_sweep_is_idempotent_across_repeated_runs(): void
    {
        Notification::fake();
        ['conversion' => $conv] = $this->scenario();

        app(ConversionService::class)->runApprovalSweep();
        app(ConversionService::class)->runApprovalSweep();
        app(ConversionService::class)->runApprovalSweep();

        // Only one approval + one notification, no matter how many times we sweep.
        $this->assertSame(1, AuditLog::where('action', 'conversion.approved_auto')->count());
        Notification::assertSentToTimes($conv->ambassadorProfile->user, AmbassadorConversionApprovedNotification::class, 1);
        $this->assertSame('approved', $conv->fresh()->status);
    }

    public function test_job_delegates_to_service_and_is_uniquely_named(): void
    {
        Notification::fake();
        Event::fake([ReferralConversionApproved::class]);
        ['conversion' => $conv] = $this->scenario();

        (new ApproveRipeReferralConversionsJob)->handle(app(ConversionService::class));

        $this->assertSame('approved', $conv->fresh()->status);
        $this->assertSame(
            'referral-conversion-approval-sweep',
            (new ApproveRipeReferralConversionsJob)->uniqueId(),
        );
    }

    public function test_artisan_command_runs_sweep(): void
    {
        Notification::fake();
        $this->scenario();

        $this->artisan('aio:referrals:approve-ripe')
            ->expectsOutputToContain('Approval sweep complete')
            ->assertExitCode(0);
    }

    public function test_config_window_can_be_shortened_via_env(): void
    {
        config(['referrals.conversion.approval_window_days' => 30]);
        ['conversion' => $conv] = $this->scenario(['paid_days_ago' => 20]);

        // 20 < 30 → not yet eligible.
        $this->assertSame(0, app(ConversionService::class)->runApprovalSweep()['approved']);

        config(['referrals.conversion.approval_window_days' => 7]);
        // 20 >= 7 → now eligible.
        $this->assertSame(1, app(ConversionService::class)->runApprovalSweep()['approved']);
    }

    public function test_eligible_query_matches_only_ripe_candidates(): void
    {
        Notification::fake();
        // 3 scenarios: ripe, unripe, refunded.
        $ripe = $this->scenario()['conversion'];
        $unripe = $this->scenario(['paid_days_ago' => 2])['conversion'];
        $refunded = $this->scenario(['payment_status' => 'refunded'])['conversion'];

        $ids = app(ConversionService::class)->eligibleForApprovalQuery()->pluck('id')->all();

        $this->assertContains($ripe->id, $ids);
        $this->assertNotContains($unripe->id, $ids);
        $this->assertNotContains($refunded->id, $ids);
    }
}
