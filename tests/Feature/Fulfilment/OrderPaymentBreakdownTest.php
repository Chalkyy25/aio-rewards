<?php

namespace Tests\Feature\Fulfilment;

use App\Models\Package;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_account_credit_and_card_payment_breakdown(): void
    {
        $purchase = $this->makeOrderPurchase([
            'amount_minor' => 8500,
            'account_credit_applied_minor' => 6000,
            'external_amount_minor' => 2500,
            'status' => 'paid',
        ]);

        $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee('Order total')
            ->assertDontSee('Amount paid')
            ->assertSee('data-testid="order-total"', false)
            ->assertSeeText('£85.00')
            ->assertSee('Account Credit')
            ->assertSee('data-testid="order-account-credit"', false)
            ->assertSeeText('£60.00')
            ->assertSee('Card payment')
            ->assertSee('data-testid="order-card-payment"', false)
            ->assertSeeText('£25.00')
            ->assertSee('Payment status')
            ->assertSee('data-testid="order-payment-status"', false)
            ->assertSeeText('Paid');
    }

    public function test_full_card_payment_hides_account_credit_row(): void
    {
        $purchase = $this->makeOrderPurchase([
            'amount_minor' => 8500,
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => 8500,
            'status' => 'paid',
        ]);

        $html = $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee('Order total')
            ->assertSeeText('£85.00')
            ->assertSee('Card payment')
            ->assertSeeText('£85.00')
            ->assertSeeText('Paid')
            ->assertDontSee('data-testid="order-account-credit"', false)
            ->getContent();

        $this->assertStringNotContainsString('Account Credit', $html);
    }

    public function test_full_account_credit_hides_zero_card_payment_row(): void
    {
        $purchase = $this->makeOrderPurchase([
            'amount_minor' => 6000,
            'account_credit_applied_minor' => 6000,
            'external_amount_minor' => 0,
            'status' => 'paid',
        ]);

        $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee('Order total')
            ->assertSeeText('£60.00')
            ->assertSee('Account Credit')
            ->assertSee('data-testid="order-account-credit"', false)
            ->assertDontSee('data-testid="order-card-payment"', false)
            ->assertDontSee('Card payment')
            ->assertSeeText('Paid');
    }

    public function test_legacy_purchase_without_external_split_does_not_invent_card_row(): void
    {
        $purchase = $this->makeOrderPurchase([
            'amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => null,
            'status' => 'paid',
        ]);

        $html = $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee('Order total')
            ->assertSeeText('£60.00')
            ->assertSeeText('Paid')
            ->assertDontSee('data-testid="order-account-credit"', false)
            ->assertDontSee('data-testid="order-card-payment"', false)
            ->getContent();

        $this->assertStringNotContainsString('Account Credit', $html);
        $this->assertStringNotContainsString('Card payment', $html);
    }

    public function test_pending_mixed_payment_shows_breakdown_with_pending_status(): void
    {
        $purchase = $this->makeOrderPurchase([
            'amount_minor' => 8500,
            'account_credit_applied_minor' => 6000,
            'external_amount_minor' => 2500,
            'status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
        ]);

        $this->get('/order/'.$purchase->customer_view_token)
            ->assertOk()
            ->assertSee('Order total')
            ->assertSeeText('£85.00')
            ->assertSee('Account Credit')
            ->assertSeeText('£60.00')
            ->assertSee('Card payment')
            ->assertSeeText('£25.00')
            ->assertSee('Payment status')
            ->assertSeeText('Pending');
    }

    public function test_null_account_credit_on_model_is_treated_as_zero(): void
    {
        $purchase = Purchase::make([
            'amount_minor' => 8500,
            'account_credit_applied_minor' => null,
            'external_amount_minor' => 8500,
            'currency' => 'gbp',
            'status' => 'paid',
        ]);

        $this->assertSame(0, $purchase->accountCreditAppliedForDisplay());
        $this->assertFalse($purchase->showsAccountCreditRow());
        $this->assertTrue($purchase->showsCardPaymentRow());
        $this->assertSame('£85.00', $purchase->formatAmountMinor(8500));
    }

    public function test_legacy_null_external_amount_hides_card_row_on_model(): void
    {
        $purchase = Purchase::make([
            'amount_minor' => 6000,
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => null,
            'currency' => 'gbp',
        ]);

        $this->assertFalse($purchase->hasExternalAmountSplit());
        $this->assertFalse($purchase->showsCardPaymentRow());
        $this->assertFalse($purchase->showsAccountCreditRow());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeOrderPurchase(array $overrides): Purchase
    {
        $package = Package::factory()->create();

        return Purchase::factory()->create(array_merge([
            'package_id' => $package->id,
            'currency' => 'gbp',
            'fulfilment_status' => 'payment_received',
            'customer_view_token' => bin2hex(random_bytes(16)),
        ], $overrides));
    }
}
