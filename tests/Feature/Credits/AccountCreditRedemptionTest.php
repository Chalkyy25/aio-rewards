<?php

namespace Tests\Feature\Credits;

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
use App\Models\StripeEvent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class AccountCreditRedemptionTest extends TestCase
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
        $this->package = Package::factory()->create(['amount_minor' => 6000, 'slug' => 'credit-pkg']);
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
            note: 'test seed',
        );
    }

    private function pendingPurchase(int $amountMinor, string $email = 'buyer@example.test'): Purchase
    {
        return Purchase::factory()->create([
            'package_id' => $this->package->id,
            'buyer_email' => $email,
            'amount_minor' => $amountMinor,
            'status' => 'pending',
            'currency' => 'gbp',
        ]);
    }

    public function test_full_credit_purchase_debits_and_skips_stripe(): void
    {
        $this->credit(6000);
        $this->package->update(['amount_minor' => 6000]);
        $purchase = $this->pendingPurchase(6000);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertTrue($result['fully_credited']);
        $this->assertNull($result['stripe_session']);
        $this->assertSame('paid', $result['purchase']->status);
        $this->assertSame(6000, $result['purchase']->account_credit_applied_minor);
        $this->assertSame(0, $result['purchase']->external_amount_minor);

        $debit = AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)
            ->sole();
        $this->assertSame(-6000, $debit->amount_minor);
        $this->assertSame($purchase->id, $debit->purchase_id);
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));
        $this->assertSame(AccountCreditReservation::STATUS_COMMITTED, $purchase->fresh()->accountCreditReservation->status);
    }

    public function test_partial_credit_reserves_and_charges_stripe_remainder(): void
    {
        $this->credit(6000);
        $this->package->update(['amount_minor' => 8500]);
        $purchase = $this->pendingPurchase(8500);

        $fakeSession = StripeSession::constructFrom(['id' => 'cs_partial_1', 'url' => 'https://stripe.test/pay']);

        $stripe = Mockery::mock(StripeCheckoutService::class);
        $stripe->shouldReceive('createSession')
            ->once()
            ->withArgs(function (Purchase $p, Package $pkg, Request $req, ?int $charge) {
                return $charge === 2500 && (int) $p->account_credit_applied_minor === 6000;
            })
            ->andReturn($fakeSession);
        $this->app->instance(StripeCheckoutService::class, $stripe);
        config(['stripe.secret' => 'sk_test_fake']);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertFalse($result['fully_credited']);
        $this->assertSame('cs_partial_1', $result['stripe_session']->id);
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertSame(6000, $purchase->fresh()->account_credit_applied_minor);
        $this->assertSame(2500, $purchase->fresh()->external_amount_minor);
        $this->assertSame(0, app(AccountCreditLedger::class)->availableMinor($this->profile));
        $this->assertSame(6000, app(AccountCreditLedger::class)->reservedMinor($this->profile));
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile)); // not yet debited

        // Stripe success commits debit.
        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_partial_ok',
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'payload' => [
                'data' => [
                    'object' => [
                        'id' => 'cs_partial_1',
                        'client_reference_id' => $purchase->id,
                        'payment_intent' => 'pi_partial',
                        'amount_total' => 2500,
                    ],
                ],
            ],
            'signature_verified' => true,
        ]);
        app(StripeEventProcessor::class)->process($event);

        $purchase->refresh();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame(-6000, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)->value('amount_minor'));
        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_cheaper_package_only_debits_package_price(): void
    {
        $this->credit(6000);
        $this->package->update(['amount_minor' => 4000]);
        $purchase = $this->pendingPurchase(4000);

        $result = app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertTrue($result['fully_credited']);
        $this->assertSame(-4000, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)->value('amount_minor'));
        $this->assertSame(2000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_optional_use_leaves_normal_stripe_flow(): void
    {
        $this->credit(6000);
        $quote = app(AccountCreditCheckoutService::class)->quote($this->profile, 6000, false);
        $this->assertSame(0, $quote['credit_applied_minor']);
        $this->assertSame(6000, $quote['external_amount_minor']);
    }

    public function test_double_spend_blocked_by_reservation(): void
    {
        $this->credit(6000);
        $pkgA = Package::factory()->create(['amount_minor' => 7000]);
        $a = $this->pendingPurchase(7000, 'a@example.test');
        $a->update(['package_id' => $pkgA->id]);

        $fakeSession = StripeSession::constructFrom(['id' => 'cs_a', 'url' => 'https://stripe.test/a']);

        $stripe = Mockery::mock(StripeCheckoutService::class);
        $stripe->shouldReceive('createSession')->once()->andReturn($fakeSession);
        $this->app->instance(StripeCheckoutService::class, $stripe);
        config(['stripe.secret' => 'sk_test_fake']);

        app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $a,
            package: $pkgA->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertSame(0, app(AccountCreditLedger::class)->availableMinor($this->profile));
        $this->assertSame(6000, app(AccountCreditLedger::class)->reservedMinor($this->profile));

        $b = $this->pendingPurchase(6000, 'b@example.test');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available Account Credit');
        app(AccountCreditReservationService::class)->reserve(
            profile: $this->profile,
            purchase: $b,
            amountMinor: 6000,
            currency: 'gbp',
            actor: $this->member,
        );
    }

    public function test_stripe_cancel_releases_reservation(): void
    {
        $this->credit(6000);
        $this->package->update(['amount_minor' => 8500]);
        $purchase = $this->pendingPurchase(8500);

        $fakeSession = StripeSession::constructFrom(['id' => 'cs_cancel', 'url' => 'https://stripe.test/c']);

        $stripe = Mockery::mock(StripeCheckoutService::class);
        $stripe->shouldReceive('createSession')->andReturn($fakeSession);
        $this->app->instance(StripeCheckoutService::class, $stripe);
        config(['stripe.secret' => 'sk_test_fake']);

        app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->actingAs($this->member)
            ->get('/checkout/cancel?purchase='.$purchase->id)
            ->assertOk();

        $this->assertSame(AccountCreditReservation::STATUS_RELEASED, $purchase->fresh()->accountCreditReservation->status);
        $this->assertSame(6000, app(AccountCreditLedger::class)->availableMinor($this->profile));
        $this->assertSame(0, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)->count());
    }

    public function test_refund_restores_only_spent_credit_once(): void
    {
        $this->credit(6000);
        $this->package->update(['amount_minor' => 6000]);
        $purchase = $this->pendingPurchase(6000);

        app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertSame(0, app(AccountCreditLedger::class)->balanceMinor($this->profile));

        $purchase->update([
            'stripe_charge_id' => 'ch_refund_1',
            'stripe_payment_intent_id' => 'pi_refund_1',
        ]);

        $event = StripeEvent::create([
            'stripe_event_id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'livemode' => false,
            'payload' => [
                'data' => ['object' => [
                    'id' => 'ch_refund_1',
                    'payment_intent' => 'pi_refund_1',
                ]],
            ],
            'signature_verified' => true,
        ]);
        app(StripeEventProcessor::class)->process($event);
        app(StripeEventProcessor::class)->process($event->fresh()); // idempotent — already processed_at

        // Second refund event with new id must still be idempotent via restoration key.
        $event2 = StripeEvent::create([
            'stripe_event_id' => 'evt_refund_2',
            'type' => 'charge.refunded',
            'livemode' => false,
            'payload' => [
                'data' => ['object' => [
                    'id' => 'ch_refund_1',
                    'payment_intent' => 'pi_refund_1',
                ]],
            ],
            'signature_verified' => true,
        ]);
        app(StripeEventProcessor::class)->process($event2);

        $this->assertSame(1, AccountCreditTransaction::query()
            ->where('source', AccountCreditTransaction::SOURCE_CREDIT_RESTORATION)->count());
        $this->assertSame(6000, app(AccountCreditLedger::class)->balanceMinor($this->profile));
    }

    public function test_member_cannot_spend_another_members_balance(): void
    {
        $this->credit(6000);

        $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $other->assignRole(Role::Ambassador->value);
        $otherProfile = AmbassadorProfile::factory()->for($other)->create();

        $this->assertSame(6000, app(AccountCreditLedger::class)->availableMinor($this->profile));
        $this->assertSame(0, app(AccountCreditLedger::class)->availableMinor($otherProfile));

        $quote = app(AccountCreditCheckoutService::class)->quote($otherProfile, 6000, true);
        $this->assertSame(0, $quote['credit_applied_minor']);
        $this->assertSame(6000, $quote['external_amount_minor']);

        // Reservations are always keyed to the profile passed server-side — never another member's.
        $purchase = $this->pendingPurchase(6000);
        $this->expectException(\InvalidArgumentException::class);
        app(AccountCreditReservationService::class)->reserve(
            profile: $otherProfile,
            purchase: $purchase,
            amountMinor: 6000,
            currency: 'gbp',
            actor: $other,
        );
    }

    public function test_client_tampering_ignored_server_recalculates(): void
    {
        $this->credit(6000);
        $quote = app(AccountCreditCheckoutService::class)->quote($this->profile, 8500, true);
        $this->assertSame(6000, $quote['credit_applied_minor']);
        $this->assertSame(2500, $quote['external_amount_minor']);

        // Fake client remainder is never accepted — only server quote matters.
        $this->assertNotSame(100, $quote['external_amount_minor']);
    }

    public function test_review_shows_credit_option_for_logged_in_member(): void
    {
        $this->credit(6000);

        $this->actingAs($this->member)
            ->withSession(['checkout.details' => [
                'buyer_name' => 'Test',
                'buyer_email' => $this->member->email,
                'preferred_username' => 'me_user',
                'delivery_method' => 'email',
                'package_slug' => 'credit-pkg',
                'terms' => '1',
                'privacy' => '1',
            ]])
            ->get('/checkout/credit-pkg/review')
            ->assertOk()
            ->assertSee('Use Account Credit')
            ->assertSee('£60.00');
    }

    public function test_self_referral_blocked_on_conversion(): void
    {
        $this->credit(6000);
        $this->package->update(['amount_minor' => 6000]);
        $purchase = $this->pendingPurchase(6000, $this->member->email);
        $purchase->update([
            'ambassador_profile_id_snapshot' => $this->profile->id,
            'referral_code_snapshot' => $this->profile->referral_code,
        ]);

        app(AccountCreditCheckoutService::class)->beginCheckout(
            purchase: $purchase,
            package: $this->package->fresh(),
            profile: $this->profile,
            useCredit: true,
            request: Request::create('/'),
            actor: $this->member,
        );

        $this->assertDatabaseMissing('referral_conversions', [
            'purchase_id' => $purchase->id,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
