<?php

namespace Tests\Feature\Fulfilment;

use App\Domain\Billing\StripeEventProcessor;
use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\PurchasePaymentAttempt;
use App\Models\ReferralConversion;
use App\Models\StripeEvent;
use App\Notifications\AdminOrderReceivedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FulfilmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['mail.admin_address' => 'ops@example.com']);
    }

    public function test_paid_order_enters_payment_received_status_and_generates_token(): void
    {
        Notification::fake();

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'amount_minor' => 6000,
            'external_amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
        ]);
        $attempt = PurchasePaymentAttempt::create([
            'purchase_id' => $purchase->id,
            'stripe_session_id' => 'cs_paid',
            'cancel_token' => PurchasePaymentAttempt::makeCancelToken(),
            'package_amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => 6000,
            'currency' => 'gbp',
            'status' => PurchasePaymentAttempt::STATUS_OPEN,
        ]);
        $purchase->update([
            'stripe_session_id' => 'cs_paid',
            'active_payment_attempt_id' => $attempt->id,
        ]);

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_paid_p4',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => ['data' => ['object' => [
                'id' => 'cs_paid',
                'client_reference_id' => $purchase->id,
                'payment_intent' => 'pi_1',
                'amount_total' => 6000,
            ]]],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $purchase->refresh();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame(OrderStatus::PaymentReceived->value, $purchase->fulfilment_status);
        $this->assertNotNull($purchase->payment_received_at);
        $this->assertNotEmpty($purchase->customer_view_token);
        $this->assertGreaterThanOrEqual(16, strlen($purchase->customer_view_token));

        Notification::assertSentOnDemand(AdminOrderReceivedNotification::class);
    }

    public function test_paid_order_with_ambassador_creates_pending_conversion(): void
    {
        Notification::fake();

        $amb = AmbassadorProfile::factory()->create();
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'amount_minor' => 6000,
            'external_amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
            'ambassador_profile_id_snapshot' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
            'attribution_id' => '01H000000000000000000ATTR1',
        ]);
        $attempt = PurchasePaymentAttempt::create([
            'purchase_id' => $purchase->id,
            'stripe_session_id' => 'cs_ref_p4',
            'cancel_token' => PurchasePaymentAttempt::makeCancelToken(),
            'package_amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => 6000,
            'currency' => 'gbp',
            'status' => PurchasePaymentAttempt::STATUS_OPEN,
        ]);
        $purchase->update([
            'stripe_session_id' => 'cs_ref_p4',
            'active_payment_attempt_id' => $attempt->id,
        ]);

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_ref_p4',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => ['data' => ['object' => [
                'id' => 'cs_ref_p4',
                'client_reference_id' => $purchase->id,
                'payment_intent' => 'pi_ref_p4',
                'amount_total' => 6000,
            ]]],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $this->assertDatabaseHas('referral_conversions', [
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $amb->id,
            'status' => 'pending',
            'amount_minor' => $purchase->amount_minor,
        ]);

        $conv = ReferralConversion::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($conv->pending_until);
        $this->assertTrue($conv->pending_until->isAfter(now()));
    }

    public function test_conversion_is_reversed_on_refund(): void
    {
        Notification::fake();

        $amb = AmbassadorProfile::factory()->create();
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'stripe_charge_id' => 'ch_refunded_1',
            'ambassador_profile_id_snapshot' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
        ]);
        ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
            'status' => 'pending',
            'amount_minor' => $purchase->amount_minor,
            'currency' => 'gbp',
            'pending_until' => now()->addDays(14),
        ]);

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_refund_p4',
            'type' => 'charge.refunded',
            'livemode' => false,
            'payload' => ['data' => ['object' => ['id' => 'ch_refunded_1']]],
            'signature_verified' => true,
        ]);
        app(StripeEventProcessor::class)->process($event);

        $purchase->refresh();
        $this->assertSame('refunded', $purchase->status);
        $this->assertSame('refunded', $purchase->fulfilment_status);
        $this->assertSame('reversed', ReferralConversion::where('purchase_id', $purchase->id)->value('status'));
        $this->assertSame('refund', ReferralConversion::where('purchase_id', $purchase->id)->value('reversed_reason'));
    }

    public function test_fulfilment_service_rejects_illegal_transitions(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        app(OrderFulfilmentService::class)->transition($purchase, OrderStatus::InProgress);
    }

    public function test_fulfilment_service_stores_encrypted_credentials(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'payment_received',
        ]);

        app(OrderFulfilmentService::class)->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_final_user',
            'provisioned_password' => 'S3cret-hunter2-2026',
            'provisioned_expires_on' => now()->addYear()->format('Y-m-d'),
            'setup_instructions_md' => 'Open the app, sign in, tap Live TV.',
            'download_links' => [['label' => 'Android APK', 'url' => 'https://example.com/aio.apk']],
        ]);

        $purchase->refresh();
        $this->assertSame('aio_final_user', $purchase->provisioned_username_enc);
        $this->assertSame('S3cret-hunter2-2026', $purchase->provisioned_password_enc);
        $this->assertSame('Android APK', $purchase->download_links[0]['label']);

        // Cipher text on disk must not equal the plaintext.
        $raw = \DB::table('purchases')->where('id', $purchase->id)->value('provisioned_password_enc');
        $this->assertNotSame('S3cret-hunter2-2026', $raw);
        $this->assertNotEmpty($raw);
    }

    public function test_public_order_status_page_shows_progress_and_hides_credentials_until_completed(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'in_progress',
            'customer_view_token' => str_repeat('a', 32),
            'setup_started_at' => now(),
        ]);
        app(OrderFulfilmentService::class)->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_secret_user',
            'provisioned_password' => 'hidden-until-done',
        ]);

        $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee($purchase->orderReference())
            ->assertSee('In progress')
            ->assertDontSee('aio_secret_user')
            ->assertDontSee('hidden-until-done');
    }

    public function test_public_order_status_page_reveals_credentials_when_completed(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'fulfilment_status' => 'completed',
            'completed_at' => now(),
            'customer_view_token' => str_repeat('b', 32),
        ]);
        app(OrderFulfilmentService::class)->updateFulfilmentDetails($purchase, [
            'provisioned_username' => 'aio_done_user',
            'provisioned_password' => 'revealed-now',
            'setup_instructions_md' => 'Open the app.',
            'download_links' => [['label' => 'Android APK', 'url' => 'https://example.com/aio.apk']],
        ]);

        $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee('aio_done_user')
            ->assertSee('revealed-now')
            ->assertSee('Android APK')
            ->assertSee('Open the app.');
    }

    public function test_public_order_status_page_returns_404_for_unknown_token(): void
    {
        $this->get('/order/'.str_repeat('z', 32))->assertNotFound();
        $this->get('/order/short')->assertNotFound();
    }
}
