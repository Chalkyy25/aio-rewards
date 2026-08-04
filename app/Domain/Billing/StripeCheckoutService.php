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

    public function createSession(Purchase $purchase, Package $package, Request $request): StripeSession
    {
        Stripe::setApiKey((string) config('stripe.secret'));

        $lineItem = $package->stripe_price_id
            ? ['price' => $package->stripe_price_id, 'quantity' => 1]
            : ['price_data' => [
                'currency' => $purchase->currency,
                'unit_amount' => $purchase->amount_minor,
                'product_data' => ['name' => $package->name, 'description' => $package->short_description],
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
            ],
        ]);
    }
}
