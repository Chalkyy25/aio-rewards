<?php

namespace App\Domain\Billing;

use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Domain\Referrals\ConversionService;
use App\Models\AmbassadorProfile;
use App\Models\Purchase;
use App\Models\StripeEvent;
use App\Notifications\AdminOrderReceivedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class StripeEventProcessor
{
    public function __construct(
        private readonly OrderFulfilmentService $fulfilment,
        private readonly ConversionService $conversions,
    ) {}

    public function process(StripeEvent $event): void
    {
        if ($event->processed_at) {
            return; // idempotent
        }

        $payload = $event->payload;
        $obj = $payload['data']['object'] ?? [];

        DB::transaction(function () use ($event, $obj) {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($obj),
                'payment_intent.succeeded' => $this->handlePaymentSucceeded($obj),
                'payment_intent.payment_failed' => $this->handlePaymentFailed($obj),
                'charge.refunded' => $this->handleRefund($obj),
                'charge.dispute.created' => $this->handleDispute($obj),
                default => null,
            };
            $event->update(['processed_at' => now()]);
        });
    }

    private function handleCheckoutCompleted(array $session): void
    {
        $purchaseId = $session['client_reference_id'] ?? ($session['metadata']['purchase_id'] ?? null);
        if (! $purchaseId) {
            return;
        }
        $purchase = Purchase::find($purchaseId);
        if (! $purchase || $purchase->status !== 'pending') {
            return; // no-op / dedup
        }

        $purchase->update([
            'status' => 'paid',
            'stripe_session_id' => $session['id'] ?? $purchase->stripe_session_id,
            'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
            'paid_at' => now(),
        ]);

        // Enter the fulfilment lifecycle.
        $this->fulfilment->markPaymentReceived($purchase);

        // Create a pending referral conversion if this order was referred.
        $this->conversions->createPendingFromPurchase($purchase->refresh());

        // Notify admins that a new paid order needs fulfilment.
        Notification::route('mail', (string) config('mail.admin_address', config('mail.from.address')))
            ->notify(new AdminOrderReceivedNotification($purchase));

        AuditLogger::record(action: 'purchase.paid', subject: $purchase, after: [
            'stripe_session_id' => $purchase->stripe_session_id,
        ]);
    }

    private function handlePaymentSucceeded(array $pi): void
    {
        Purchase::where('stripe_payment_intent_id', $pi['id'] ?? '__none__')
            ->update(['stripe_charge_id' => $pi['latest_charge'] ?? null]);
    }

    private function handlePaymentFailed(array $pi): void
    {
        Purchase::where('stripe_payment_intent_id', $pi['id'] ?? '__none__')
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }

    private function handleRefund(array $charge): void
    {
        $purchase = Purchase::where('stripe_charge_id', $charge['id'] ?? null)
            ->orWhere('stripe_payment_intent_id', $charge['payment_intent'] ?? '__none__')
            ->first();
        if ($purchase && $purchase->status === 'paid') {
            $purchase->update(['status' => 'refunded']);
            $this->fulfilment->transition($purchase, OrderStatus::Refunded);
            $this->conversions->reverseByPurchase($purchase, 'refund');
            AuditLogger::record(action: 'purchase.refunded', subject: $purchase);
        }
    }

    private function handleDispute(array $dispute): void
    {
        $purchase = Purchase::where('stripe_charge_id', $dispute['charge'] ?? null)->first();
        if (! $purchase) {
            return;
        }
        $purchase->update(['status' => 'chargeback']);
        try {
            $this->fulfilment->transition($purchase, OrderStatus::Refunded);
        } catch (\DomainException) {
            // Ignore illegal transitions from terminal states.
        }
        $this->conversions->reverseByPurchase($purchase, 'chargeback');
        if ($purchase->ambassador_profile_id_snapshot) {
            AmbassadorProfile::where('id', $purchase->ambassador_profile_id_snapshot)
                ->update(['flagged_for_review' => true, 'flagged_reason' => 'chargeback']);
        }
        AuditLogger::record(action: 'purchase.chargeback', subject: $purchase);
    }
}
