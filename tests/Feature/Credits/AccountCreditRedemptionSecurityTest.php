<?php

namespace Tests\Feature\Credits;

use App\Console\Commands\ExpireStaleAccountCreditReservationsCommand;
use App\Domain\Billing\StripeCheckoutService;
use App\Domain\Billing\StripeEventProcessor;
use App\Domain\Credits\AccountCreditCheckoutService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Credits\AccountCreditReservationService;
use App\Enums\Role;
use App\Models\AccountCreditReservation;
use App\Models\AccountCreditTransaction;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\PurchasePaymentAttempt;
use App\Models\StripeEvent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class AccountCreditRedemptionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private AmbassadorProfile $profile;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->member = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->member->assignRole(Role::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->for($this->member)->create();
        $this->package = Package::factory()->create(['amount_minor' => 8500, 'slug' => 'sec-pkg']);
    }

    private function credit(int $amountMinor): void
    {
        app(AccountCreditLedger::class)->post(
            profile: $this->profile,
            signedAmountMinor: $amountMinor,
            currency: 'gbp',
            source: AccountCreditTransaction::SOURCE_ADMIN_ADJUSTMENT,
            idempotencyKey: 'seed:'.$amountMinor.':'.uniqid(),
            origin: 'admin',
        );
    }

    private function mockStripeSession(string $id): void
    {
        $fake = StripeSession::constructFrom(['id' => $id, 'url' => 'https://stripe.test/'.$id]);
        $stripe = Mockery::mock(StripeCheckoutService::class);
        $stripe->shouldReceive('createSession')->andReturn($fake);
        $this->app->instance(StripeCheckoutService::class, $stripe);
        config(['stripe.secret' => 'sk_test_fake']);
    }

    public function test_old_partial_session_cannot_underpay_after_payment_mix_cleared(): void
    {
        $this->credit(6000);
        $this->mockStripeSession('cs_partial_old');

        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'buyer_email' => 'buyer@example.test',
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $resultA = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package,
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $attemptA = $resultA['attempt'];
        $this->assertSame(6000, $attemptA->account_credit_applied_minor);
        $this->assertSame(2500, $attemptA->external_amount_minor);
        $this->assertSame(PurchasePaymentAttempt::STATUS_OPEN, $attemptA->status);

        // Switch to no-credit checkout on the same pending purchase.
        $this->mockStripeSession('cs_full_new');
        $resultB = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase->fresh(),
            package: $this->package,
            profile: null,
            useCredit: false,
            request: Request::create('/'),
            actor: $this->member,
        );

        $attemptA->refresh();
        $this->assertSame(PurchasePaymentAttempt::STATUS_SUPERSEDED, $attemptA->status);
        $this->assertSame(PurchasePaymentAttempt::STATUS_OPEN, $resultB['attempt']->status);
        $this->assertSame(0, $resultB['attempt']->account_credit_applied_minor);
        $this->assertSame(8500, $resultB['attempt']->external_amount_minor);

        // Completing the OLD partial session must NOT mark the purchase paid.
        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_stale_partial',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => [
                'data' => ['object' => [
                    'id' => 'cs_partial_old',
                    'client_reference_id' => $purchase->id,
                    'amount_total' => 2500,
                    'payment_intent' => 'pi_stale',
                ]],
            ],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at); // event absorbed, not retried forever
        $this->assertSame(PurchasePaymentAttempt::STATUS_SUPERSEDED, $attemptA->fresh()->status);
    }

    public function test_amount_total_null_does_not_complete_purchase(): void
    {
        $this->mockStripeSession('cs_null_amt');
        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package,
            profile: null,
            useCredit: false,
            request: Request::create('/'),
        );

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_null_amt',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => [
                'data' => ['object' => [
                    'id' => $result['attempt']->stripe_session_id,
                    'client_reference_id' => $purchase->id,
                    'amount_total' => null,
                ]],
            ],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_amount_mismatch_does_not_complete_purchase(): void
    {
        $this->mockStripeSession('cs_mismatch');
        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package,
            profile: null,
            useCredit: false,
            request: Request::create('/'),
        );

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_mismatch',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => [
                'data' => ['object' => [
                    'id' => $result['attempt']->stripe_session_id,
                    'client_reference_id' => $purchase->id,
                    'amount_total' => 100, // wrong
                ]],
            ],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);

        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_valid_session_completes_with_amount_match(): void
    {
        $this->mockStripeSession('cs_ok');
        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package,
            profile: null,
            useCredit: false,
            request: Request::create('/'),
        );

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_ok',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => [
                'data' => ['object' => [
                    'id' => $result['attempt']->stripe_session_id,
                    'client_reference_id' => $purchase->id,
                    'amount_total' => 8500,
                    'payment_intent' => 'pi_ok',
                ]],
            ],
            'signature_verified' => true,
        ]);

        app(StripeEventProcessor::class)->process($event);
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertSame(PurchasePaymentAttempt::STATUS_COMPLETED, $result['attempt']->fresh()->status);

        // Duplicate webhook is idempotent.
        app(StripeEventProcessor::class)->process($event->fresh());
        $this->assertSame('paid', $purchase->fresh()->status);
    }

    public function test_cancel_requires_valid_token_and_blocks_idor(): void
    {
        $this->credit(6000);
        $this->mockStripeSession('cs_cancel_auth');
        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package,
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $attempt = $result['attempt'];

        // Wrong token — no release.
        $this->get('/checkout/cancel?attempt='.$attempt->id.'&token=wrong')
            ->assertOk();
        $this->assertSame(PurchasePaymentAttempt::STATUS_OPEN, $attempt->fresh()->status);
        $this->assertSame(AccountCreditReservation::STATUS_PENDING, $purchase->fresh()->accountCreditReservation->status);

        // Other member with correct token still cannot cancel credit reservation.
        $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $other->assignRole(Role::Ambassador->value);
        AmbassadorProfile::factory()->for($other)->create();

        $this->actingAs($other)
            ->get('/checkout/cancel?attempt='.$attempt->id.'&token='.$attempt->cancel_token)
            ->assertOk();
        $this->assertSame(PurchasePaymentAttempt::STATUS_OPEN, $attempt->fresh()->status);

        // Owning member with token can cancel.
        $this->actingAs($this->member)
            ->get('/checkout/cancel?attempt='.$attempt->id.'&token='.$attempt->cancel_token)
            ->assertOk();
        $this->assertSame(PurchasePaymentAttempt::STATUS_CANCELLED, $attempt->fresh()->status);
        $this->assertSame(AccountCreditReservation::STATUS_RELEASED, $purchase->fresh()->accountCreditReservation->status);
    }

    public function test_stale_reservation_expiry_command(): void
    {
        $this->credit(6000);
        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $reservation = app(AccountCreditReservationService::class)->reserve(
            profile: $this->profile,
            purchase: $purchase,
            amountMinor: 6000,
            currency: 'gbp',
            actor: $this->member,
            ttlMinutes: 60,
        );

        // Fresh — not expired.
        Artisan::call(ExpireStaleAccountCreditReservationsCommand::class);
        $this->assertSame(AccountCreditReservation::STATUS_PENDING, $reservation->fresh()->status);

        $reservation->update(['expires_at' => now()->subMinute()]);
        Artisan::call('aio:expire-account-credit-reservations');
        $this->assertSame(AccountCreditReservation::STATUS_EXPIRED, $reservation->fresh()->status);

        // Idempotent re-run.
        Artisan::call('aio:expire-account-credit-reservations');
        $this->assertSame(AccountCreditReservation::STATUS_EXPIRED, $reservation->fresh()->status);
    }

    public function test_committed_and_released_reservations_unaffected_by_sweeper(): void
    {
        $this->credit(6000);
        $p1 = Purchase::factory()->create(['package_id' => $this->package->id, 'amount_minor' => 4000, 'status' => 'pending']);
        $p2 = Purchase::factory()->create(['package_id' => $this->package->id, 'amount_minor' => 2000, 'status' => 'pending']);

        $committed = app(AccountCreditReservationService::class)->reserve(
            profile: $this->profile,
            purchase: $p1,
            amountMinor: 4000,
            currency: 'gbp',
        );
        app(AccountCreditReservationService::class)->commit($committed);
        $committed->update(['expires_at' => now()->subHour()]);

        $released = app(AccountCreditReservationService::class)->reserve(
            profile: $this->profile,
            purchase: $p2,
            amountMinor: 2000,
            currency: 'gbp',
        );
        app(AccountCreditReservationService::class)->release($released);
        $released->update(['expires_at' => now()->subHour()]);

        Artisan::call('aio:expire-account-credit-reservations');

        $this->assertSame(AccountCreditReservation::STATUS_COMMITTED, $committed->fresh()->status);
        $this->assertSame(AccountCreditReservation::STATUS_RELEASED, $released->fresh()->status);
    }

    public function test_partial_refund_allocation_policy(): void
    {
        $this->credit(6000);
        $pkg = Package::factory()->create(['amount_minor' => 8500]);
        $purchase = Purchase::factory()->create([
            'package_id' => $pkg->id,
            'amount_minor' => 8500,
            'status' => 'pending',
        ]);

        $this->mockStripeSession('cs_ref');
        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $pkg,
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        app(StripeEventProcessor::class)->process(StripeEvent::create([
            'stripe_event_id' => 'evt_ref_pay',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => ['data' => ['object' => [
                'id' => $result['attempt']->stripe_session_id,
                'client_reference_id' => $purchase->id,
                'amount_total' => 2500,
                'payment_intent' => 'pi_ref',
            ]]],
            'signature_verified' => true,
        ]));

        $purchase->refresh();
        $purchase->update(['stripe_charge_id' => 'ch_ref', 'stripe_payment_intent_id' => 'pi_ref']);
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        $svc = app(AccountCreditCheckoutService::class);

        // £10 partial external refund → £0 AC
        $svc->restoreAfterExternalRefund($purchase->fresh(), 1000);
        $this->assertSame(0, $purchase->fresh()->account_credit_restored_minor);
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        // Cumulative £25 (full external) → still £0 AC
        $svc->restoreAfterExternalRefund($purchase->fresh(), 2500);
        $this->assertSame(0, $purchase->fresh()->account_credit_restored_minor);

        // Cumulative £35 → restore £10 AC
        $svc->restoreAfterExternalRefund($purchase->fresh(), 3500);
        $this->assertSame(1000, $purchase->fresh()->account_credit_restored_minor);
        $this->assertSame(1000, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        // Full £85 → restore remaining £50 (total £60)
        $svc->restoreAfterExternalRefund($purchase->fresh(), 8500);
        $this->assertSame(6000, $purchase->fresh()->account_credit_restored_minor);
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        // Repeat full — no over-restore
        $svc->restoreAfterExternalRefund($purchase->fresh(), 8500);
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_CREDIT_RESTORATION)
            ->where('idempotency_key', 'credit_restoration:'.$purchase->id.':to:1000')
            ->count());
        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('idempotency_key', 'credit_restoration:'.$purchase->id.':to:6000')
            ->count());
    }

    public function test_fully_ac_funded_refund_restores_debit(): void
    {
        $this->credit(6000);
        $pkg = Package::factory()->create(['amount_minor' => 6000]);
        $purchase = Purchase::factory()->create([
            'package_id' => $pkg->id,
            'amount_minor' => 6000,
            'status' => 'pending',
        ]);

        app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $pkg,
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        app(AccountCreditCheckoutService::class)->restoreFullyCreditedPurchase($purchase->fresh());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        app(AccountCreditCheckoutService::class)->restoreFullyCreditedPurchase($purchase->fresh());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
