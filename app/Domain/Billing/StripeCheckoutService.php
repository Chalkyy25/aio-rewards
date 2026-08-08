<?php

namespace App\Domain\Billing;

use App\Models\Package;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

class StripeCheckoutService
{
    public static function isConfigured(): bool
    {
        return (bool) config('stripe.secret');
    }

    /**
     * @param int|null $chargeAmountMinor When set, Stripe charges this amount (partial AC).
     *                                    When null, charges the full purchase amount.
     *                                    Always use price_data for partial amounts — never the
     *                                    catalogue stripe_price_id (which would charge full price).
     */
    public function createSession(
        Purchase $purchase,
        Package $package,
        Request $request,
        ?int $chargeAmountMinor = null,
    ): StripeSession {
        Stripe::setApiKey((string) config('stripe.secret'));

        $amountToCharge = $chargeAmountMinor ?? (int) $purchase->amount_minor;
        if ($amountToCharge <= 0) {
            throw new \InvalidArgumentException('Stripe charge amount must be positive.');
        }

        $creditApplied = (int) ($purchase->account_credit_applied_minor ?? 0);
        $useCustomAmount = $chargeAmountMinor !== null || $creditApplied > 0 || ! $package->stripe_price_id;

        $lineItem = (! $useCustomAmount && $package->stripe_price_id)
            ? ['price' => $package->stripe_price_id, 'quantity' => 1]
            : ['price_data' => [
                'currency' => $purchase->currency,
                'unit_amount' => $amountToCharge,
                'product_data' => [
                    'name' => $package->name.($creditApplied > 0 ? ' (balance after Account Credit)' : ''),
                    'description' => $package->short_description,
                ],
            ], 'quantity' => 1];

        return StripeSession::create([
            'mode' => 'payment',
            'line_items' => [$lineItem],
            'client_reference_id' => $purchase->id,
            'customer_email' => $purchase->buyer_email,
            'success_url' => url('/checkout/success?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/checkout/cancel?purchase='.$purchase->id),
            'metadata' => [
                'purchase_id' => $purchase->id,
                'package_id' => (string) $package->id,
                'attribution_id' => (string) $purchase->attribution_id,
                'referral_code' => (string) $purchase->referral_code_snapshot,
                'preferred_username' => $purchase->preferred_username,
                'original_amount_minor' => (string) $purchase->amount_minor,
                'account_credit_applied_minor' => (string) $creditApplied,
                'external_amount_minor' => (string) $amountToCharge,
            ],
        ]);
    }
}
