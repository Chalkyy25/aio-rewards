<?php

namespace Tests\Feature\Checkout;

use App\Domain\Billing\StripeEventProcessor;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\StripeEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['stripe.webhook_secret' => 'whsec_test_secret']);
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        $this->postJson('/webhooks/stripe', ['id' => 'evt_1'], ['Stripe-Signature' => ''])
            ->assertStatus(400);
    }

    public function test_webhook_stores_event_and_dispatches_job_with_valid_signature(): void
    {
        Queue::fake();

        $payload = json_encode([
            'id' => 'evt_test_1',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'data' => ['object' => ['id' => 'cs_test_1']],
        ]);
        $timestamp = time();
        $sig = $this->signStripe($payload, $timestamp, 'whsec_test_secret');

        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$sig}"],
            $payload
        );

        $response->assertOk();
        $this->assertDatabaseHas('stripe_events', ['stripe_event_id' => 'evt_test_1']);
        Queue::assertPushed(\App\Jobs\ProcessStripeEventJob::class);
    }

    public function test_webhook_is_idempotent_for_duplicate_event_ids(): void
    {
        $payload = json_encode([
            'id' => 'evt_dupe',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'data' => ['object' => []],
        ]);
        $ts = time();
        $sig = $this->signStripe($payload, $ts, 'whsec_test_secret');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}"];

        $this->call('POST', '/webhooks/stripe', [], [], [], $headers, $payload)->assertOk();
        $this->call('POST', '/webhooks/stripe', [], [], [], $headers, $payload)->assertOk();

        $this->assertSame(1, StripeEvent::where('stripe_event_id', 'evt_dupe')->count());
    }

    public function test_processor_marks_purchase_paid_on_checkout_completed(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'pending',
            'stripe_session_id' => 'cs_pre',
        ]);
        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_paid_1',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => [
                'data' => [
                    'object' => [
                        'id' => 'cs_new',
                        'client_reference_id' => $purchase->id,
                        'payment_intent' => 'pi_test_new',
                    ],
                ],
            ],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $purchase->refresh();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame('pi_test_new', $purchase->stripe_payment_intent_id);
        $this->assertNotNull($purchase->paid_at);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_processor_is_idempotent_on_re_process(): void
    {
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'pending',
        ]);
        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_re',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => ['data' => ['object' => ['client_reference_id' => $purchase->id]]],
            'signature_verified' => true,
            'processed_at' => now(),
        ]);

        app(StripeEventProcessor::class)->process($event);

        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_processor_flags_ambassador_on_chargeback(): void
    {
        $amb = AmbassadorProfile::factory()->create(['flagged_for_review' => false]);
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'stripe_charge_id' => 'ch_test_cb',
            'ambassador_profile_id_snapshot' => $amb->id,
        ]);
        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_cb',
            'type' => 'charge.dispute.created',
            'livemode' => false,
            'payload' => ['data' => ['object' => ['charge' => 'ch_test_cb']]],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $this->assertSame('chargeback', $purchase->fresh()->status);
        $this->assertTrue((bool) $amb->fresh()->flagged_for_review);
    }

    private function signStripe(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
