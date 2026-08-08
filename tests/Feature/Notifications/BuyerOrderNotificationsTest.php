<?php

namespace Tests\Feature\Notifications;

use App\Domain\Billing\StripeEventProcessor;
use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Domain\Notifications\BuyerOrderNotifier;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\PurchasePaymentAttempt;
use App\Models\StripeEvent;
use App\Notifications\BuyerOrderCompletedNotification;
use App\Notifications\BuyerPaymentReceivedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BuyerOrderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['support.contact_email' => 'help@example.com']);
    }

    public function test_payment_received_email_is_sent_when_stripe_confirms_payment(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'buyer_email' => 'buyer@example.com',
            'buyer_name' => 'Alex Buyer',
            'amount_minor' => 6000,
            'external_amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
        ]);
        $attempt = PurchasePaymentAttempt::create([
            'purchase_id' => $purchase->id,
            'stripe_session_id' => 'cs_email_p1',
            'cancel_token' => PurchasePaymentAttempt::makeCancelToken(),
            'package_amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => 6000,
            'currency' => 'gbp',
            'status' => PurchasePaymentAttempt::STATUS_OPEN,
        ]);
        $purchase->update([
            'stripe_session_id' => 'cs_email_p1',
            'active_payment_attempt_id' => $attempt->id,
        ]);

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_email_p1',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => ['data' => ['object' => [
                'id' => 'cs_email_p1',
                'client_reference_id' => $purchase->id,
                'payment_intent' => 'pi_email_p1',
                'amount_total' => 6000,
            ]]],
            'signature_verified' => true,
        ]);
        app(StripeEventProcessor::class)->process($event);

        Notification::assertSentOnDemand(
            BuyerPaymentReceivedNotification::class,
            function ($notification, array $channels, $notifiable) {
                return in_array('buyer@example.com', (array) $notifiable->routes['mail'], true);
            }
        );

        $purchase->refresh();
        $this->assertNotNull($purchase->payment_email_sent_at);
    }

    public function test_payment_received_email_is_not_sent_twice_for_same_purchase(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'buyer_email' => 'buyer@example.com',
        ]);
        $notifier = app(BuyerOrderNotifier::class);

        $this->assertTrue($notifier->sendPaymentReceived($purchase));
        $this->assertFalse($notifier->sendPaymentReceived($purchase->fresh()));

        Notification::assertSentOnDemandTimes(BuyerPaymentReceivedNotification::class, 1);
        $this->assertTrue(AuditLog::where('action', 'email.buyer_payment_received.sent')->exists());
        $this->assertTrue(AuditLog::where('action', 'email.buyer_payment_received.skipped_duplicate')->exists());
    }

    public function test_completed_email_is_sent_when_transitioning_to_completed(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'in_progress',
            'buyer_email' => 'buyer@example.com',
        ]);
        $svc = app(OrderFulfilmentService::class);
        $svc->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_complete_user',
            'provisioned_password' => 'complete-secret-2026',
        ]);

        $svc->transition($purchase->fresh(), OrderStatus::Completed);

        Notification::assertSentOnDemand(BuyerOrderCompletedNotification::class);
        $this->assertNotNull($purchase->fresh()->completed_email_sent_at);
    }

    public function test_completed_email_is_not_sent_twice_when_transitions_repeat(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'in_progress',
            'buyer_email' => 'buyer@example.com',
        ]);

        $svc = app(OrderFulfilmentService::class);
        $svc->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_complete_user',
            'provisioned_password' => 'complete-secret-2026',
        ]);
        $svc->transition($purchase->fresh(), OrderStatus::AwaitingCustomer);
        $svc->transition($purchase->fresh(), OrderStatus::Completed);
        // Try to move backwards and forwards again (only refund would be legal from completed; skip forced retry via notifier).
        app(BuyerOrderNotifier::class)->sendOrderCompleted($purchase->fresh());

        Notification::assertSentOnDemandTimes(BuyerOrderCompletedNotification::class, 1);
    }

    public function test_completed_email_never_contains_the_plaintext_password(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'in_progress',
            'buyer_email' => 'buyer@example.com',
            'customer_view_token' => str_repeat('t', 32),
        ]);
        app(OrderFulfilmentService::class)->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_final_name',
            'provisioned_password' => 'SUPER-SECRET-PW-2026',
        ]);
        app(OrderFulfilmentService::class)->transition($purchase->fresh(), OrderStatus::Completed);

        Notification::assertSentOnDemand(
            BuyerOrderCompletedNotification::class,
            function (BuyerOrderCompletedNotification $notification, array $channels, $notifiable) {
                $rendered = $notification->toMail($notifiable)->render();
                $this->assertStringNotContainsString('SUPER-SECRET-PW-2026', $rendered);
                $this->assertStringContainsString('aio_final_name', $rendered);
                $this->assertStringContainsString('/order/'.str_repeat('t', 32), $rendered);

                return true;
            }
        );
    }

    public function test_email_link_targets_only_the_buyers_own_order(): void
    {
        $package = Package::factory()->create();
        $mine = Purchase::factory()->create([
            'package_id' => $package->id,
            'buyer_email' => 'mine@example.com',
            'customer_view_token' => str_repeat('m', 32),
        ]);
        $theirs = Purchase::factory()->create([
            'package_id' => $package->id,
            'customer_view_token' => str_repeat('t', 32),
        ]);

        $rendered = (new BuyerPaymentReceivedNotification($mine))->toMail((object) [])->render();
        $this->assertStringContainsString('/order/'.str_repeat('m', 32), $rendered);
        $this->assertStringNotContainsString('/order/'.str_repeat('t', 32), $rendered);
    }

    public function test_skipped_notification_is_audited_when_buyer_email_missing(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'buyer_email' => '',
        ]);

        $this->assertFalse(app(BuyerOrderNotifier::class)->sendPaymentReceived($purchase));
        Notification::assertNothingSent();
        $this->assertTrue(
            AuditLog::where('action', 'email.buyer_payment_received.skipped')->exists()
        );
    }
}
