<?php

namespace Tests\Feature\Fulfilment;

use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Domain\Referrals\ConversionService;
use App\Enums\Role;
use App\Filament\Resources\PurchaseResource\Pages\ViewPurchase;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\User;
use App\Notifications\BuyerOrderCompletedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CompleteOrderTest extends TestCase
{
    use RefreshDatabase;

    private OrderFulfilmentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->svc = app(OrderFulfilmentService::class);
        Filament::setCurrentPanel('admin');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function paidPurchase(string $fulfilment, array $overrides = []): Purchase
    {
        $package = Package::factory()->create();

        return Purchase::factory()->create(array_merge([
            'package_id' => $package->id,
            'status' => 'paid',
            'paid_at' => now()->subDays(20),
            'fulfilment_status' => $fulfilment,
            'payment_received_at' => now()->subDays(20),
            'buyer_email' => 'buyer@example.com',
            'customer_view_token' => bin2hex(random_bytes(16)),
        ], $overrides));
    }

    private function provision(Purchase $purchase): Purchase
    {
        $this->svc->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_ready_user',
            'provisioned_password' => 'ready-secret-2026',
            'setup_instructions_md' => 'Open the app and sign in.',
            'download_links' => [['label' => 'Android APK', 'url' => 'https://example.com/aio.apk']],
        ]);

        return $purchase->fresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'mfa_enabled' => false,
        ]);
        $user->assignRole(Role::Admin->value);

        return $user;
    }

    public function test_paid_payment_received_can_complete_directly(): void
    {
        Notification::fake();
        $purchase = $this->provision($this->paidPurchase('payment_received'));

        $this->svc->transition($purchase, OrderStatus::Completed);

        $purchase->refresh();
        $this->assertSame(OrderStatus::Completed->value, $purchase->fulfilment_status);
        $this->assertNotNull($purchase->completed_at);
        $this->assertNotNull($purchase->fulfilled_at);
        $this->assertNull($purchase->setup_started_at);
        $this->assertSame('aio_ready_user', $purchase->provisioned_username_enc);
        $this->assertSame('ready-secret-2026', $purchase->provisioned_password_enc);
        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);
    }

    public function test_paid_pending_setup_can_complete_directly(): void
    {
        Notification::fake();
        $purchase = $this->provision($this->paidPurchase('pending_setup'));

        $this->svc->transition($purchase, OrderStatus::Completed);

        $purchase->refresh();
        $this->assertSame(OrderStatus::Completed->value, $purchase->fulfilment_status);
        $this->assertNotNull($purchase->completed_at);
        $this->assertNull($purchase->setup_started_at);
        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);
    }

    public function test_in_progress_can_still_complete(): void
    {
        Notification::fake();
        $purchase = $this->provision($this->paidPurchase('in_progress', [
            'setup_started_at' => now()->subHour(),
        ]));

        $this->svc->transition($purchase, OrderStatus::Completed);

        $this->assertSame(OrderStatus::Completed->value, $purchase->fresh()->fulfilment_status);
        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);
    }

    public function test_awaiting_customer_can_still_complete(): void
    {
        Notification::fake();
        $purchase = $this->provision($this->paidPurchase('awaiting_customer', [
            'setup_started_at' => now()->subHour(),
            'awaiting_customer_at' => now()->subMinutes(30),
        ]));

        $this->svc->transition($purchase, OrderStatus::Completed);

        $this->assertSame(OrderStatus::Completed->value, $purchase->fresh()->fulfilment_status);
        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);
    }

    public function test_unpaid_order_cannot_complete(): void
    {
        $purchase = $this->provision(Purchase::factory()->create([
            'package_id' => Package::factory()->create()->id,
            'status' => 'pending',
            'fulfilment_status' => 'payment_received',
            'buyer_email' => 'buyer@example.com',
        ]));

        try {
            $this->svc->transition($purchase, OrderStatus::Completed);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('payment must be paid', $e->getMessage());
        }

        $purchase->refresh();
        $this->assertSame('payment_received', $purchase->fulfilment_status);
        $this->assertNull($purchase->completed_at);
        $this->assertNull($purchase->completed_email_sent_at);
    }

    public function test_missing_username_or_password_blocks_completion(): void
    {
        Notification::fake();

        $missingPassword = $this->paidPurchase('payment_received');
        $this->svc->updateFulfilmentDetails($missingPassword, [
            'provisioned_username' => 'aio_only_user',
        ]);

        try {
            $this->svc->transition($missingPassword->fresh(), OrderStatus::Completed);
            $this->fail('Expected DomainException for missing password');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('provisioned password', $e->getMessage());
        }

        $missingPassword->refresh();
        $this->assertSame('payment_received', $missingPassword->fulfilment_status);
        $this->assertNull($missingPassword->completed_at);
        $this->assertNull($missingPassword->completed_email_sent_at);
        Notification::assertNothingSent();

        $missingUsername = $this->paidPurchase('payment_received');
        $this->svc->updateFulfilmentDetails($missingUsername, [
            'provisioned_password' => 'only-password-2026',
        ]);

        try {
            $this->svc->transition($missingUsername->fresh(), OrderStatus::Completed);
            $this->fail('Expected DomainException for missing username');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('provisioned username', $e->getMessage());
        }

        $missingUsername->refresh();
        $this->assertSame('payment_received', $missingUsername->fulfilment_status);
        $this->assertNull($missingUsername->completed_at);
        Notification::assertNothingSent();
    }

    public function test_completed_order_is_noop_and_action_is_hidden(): void
    {
        Notification::fake();
        $purchase = $this->provision($this->paidPurchase('payment_received'));
        $this->svc->transition($purchase, OrderStatus::Completed);
        $purchase->refresh();
        $completedAt = $purchase->completed_at;
        $this->assertFalse($this->svc->isEligibleForCompleteAction($purchase));

        $this->svc->transition($purchase, OrderStatus::Completed);
        $purchase->refresh();
        $this->assertTrue($completedAt->equalTo($purchase->completed_at));
        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);

        $this->actingAs($this->admin());
        Livewire::test(ViewPurchase::class, ['record' => $purchase->id])
            ->assertOk()
            ->assertActionHidden('completeOrder');
    }

    public function test_filament_complete_order_visible_for_paid_payment_received(): void
    {
        $purchase = $this->provision($this->paidPurchase('payment_received'));

        $this->actingAs($this->admin());
        Livewire::test(ViewPurchase::class, ['record' => $purchase->id])
            ->assertOk()
            ->assertActionVisible('completeOrder');
    }

    public function test_filament_complete_order_hidden_for_unpaid_purchase(): void
    {
        $purchase = $this->provision(Purchase::factory()->create([
            'package_id' => Package::factory()->create()->id,
            'status' => 'pending',
            'fulfilment_status' => 'payment_received',
        ]));

        $this->assertFalse($this->svc->isEligibleForCompleteAction($purchase));

        $this->actingAs($this->admin());
        Livewire::test(ViewPurchase::class, ['record' => $purchase->id])
            ->assertOk()
            ->assertActionHidden('completeOrder');
    }

    public function test_filament_complete_order_succeeds_and_refreshes(): void
    {
        Notification::fake();
        $purchase = $this->provision($this->paidPurchase('payment_received'));
        $admin = $this->admin();

        $this->actingAs($admin);
        Livewire::test(ViewPurchase::class, ['record' => $purchase->id])
            ->callAction('completeOrder')
            ->assertNotified()
            ->assertActionHidden('completeOrder');

        $purchase->refresh();
        $this->assertSame(OrderStatus::Completed->value, $purchase->fulfilment_status);
        $this->assertNotNull($purchase->completed_at);
        $this->assertSame($admin->id, $purchase->fulfilled_by_user_id);
        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);
    }

    public function test_filament_complete_order_rejects_missing_credentials_without_side_effects(): void
    {
        Notification::fake();
        $purchase = $this->paidPurchase('payment_received');

        $this->actingAs($this->admin());
        Livewire::test(ViewPurchase::class, ['record' => $purchase->id])
            ->callAction('completeOrder')
            ->assertNotified()
            ->assertActionVisible('completeOrder');

        $purchase->refresh();
        $this->assertSame('payment_received', $purchase->fulfilment_status);
        $this->assertNull($purchase->completed_at);
        $this->assertNull($purchase->completed_email_sent_at);
        Notification::assertNothingSent();
    }

    public function test_customer_status_page_shows_ready_to_use_and_credentials_after_completion(): void
    {
        $token = str_repeat('r', 32);
        $purchase = $this->provision($this->paidPurchase('payment_received', [
            'customer_view_token' => $token,
        ]));
        $this->svc->transition($purchase, OrderStatus::Completed);

        $this->get('/order/'.$token)
            ->assertOk()
            ->assertSee('Ready to use')
            ->assertSee('aio_ready_user')
            ->assertSee('ready-secret-2026')
            ->assertSee('Open the app and sign in.')
            ->assertSee('Android APK')
            ->assertDontSee('We do not activate instantly');
    }

    public function test_intermediate_transitions_still_work(): void
    {
        $purchase = $this->paidPurchase('payment_received');

        $this->svc->transition($purchase, OrderStatus::PendingSetup);
        $this->assertSame('pending_setup', $purchase->fresh()->fulfilment_status);

        $this->svc->transition($purchase->fresh(), OrderStatus::InProgress);
        $this->assertSame('in_progress', $purchase->fresh()->fulfilment_status);
        $this->assertNotNull($purchase->fresh()->setup_started_at);

        $this->svc->transition($purchase->fresh(), OrderStatus::AwaitingCustomer);
        $this->assertSame('awaiting_customer', $purchase->fresh()->fulfilment_status);
        $this->assertNotNull($purchase->fresh()->awaiting_customer_at);
    }

    public function test_cancelled_and_refunded_terminal_protections_remain(): void
    {
        $cancelled = $this->paidPurchase('cancelled', ['cancelled_at' => now()]);
        $this->expectException(\DomainException::class);
        $this->svc->transition($cancelled, OrderStatus::Completed);
    }

    public function test_refunded_cannot_transition_to_completed(): void
    {
        $refunded = $this->paidPurchase('refunded', ['refunded_at' => now()]);

        try {
            $this->svc->transition($refunded, OrderStatus::Completed);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Illegal transition', $e->getMessage());
        }

        $this->assertFalse($this->svc->isEligibleForCompleteAction($refunded));
        $this->actingAs($this->admin());
        Livewire::test(ViewPurchase::class, ['record' => $refunded->id])
            ->assertActionHidden('completeOrder');
    }

    public function test_direct_completion_keeps_conversion_approval_eligibility(): void
    {
        $amb = AmbassadorProfile::factory()->create([
            'flagged_for_review' => false,
        ]);
        $amb->user->forceFill(['is_active' => true])->save();

        $purchase = $this->provision($this->paidPurchase('payment_received', [
            'ambassador_profile_id_snapshot' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
            'paid_at' => now()->subDays(20),
        ]));

        $conversion = app(ConversionService::class)->createPendingFromPurchase($purchase);
        $this->assertNotNull($conversion);

        $this->svc->transition($purchase->fresh(), OrderStatus::Completed);

        $this->assertTrue(
            app(ConversionService::class)->eligibleForApprovalQuery()->whereKey($conversion->id)->exists()
        );
    }
}
