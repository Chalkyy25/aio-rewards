<?php

namespace App\Domain\Billing;

use App\Domain\Credits\AccountCreditCheckoutService;
use App\Domain\Credits\AccountCreditReservationService;
use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Fulfilment\OrderStatus;
use App\Domain\Notifications\BuyerOrderNotifier;
use App\Domain\Referrals\ConversionService;
use App\Models\AmbassadorProfile;
use App\Models\Purchase;
use App\Models\PurchasePaymentAttempt;
use App\Models\StripeEvent;
use App\Notifications\AdminOrderReceivedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class StripeEventProcessor
{
    public function __construct(
        private readonly OrderFulfilmentService $fulfilment,
        private readonly ConversionService $conversions,
        private readonly BuyerOrderNotifier $buyerNotifier,
        private readonly AccountCreditCheckoutService $creditCheckout,
        private readonly AccountCreditReservationService $reservations,
        private readonly PurchasePaymentAttemptService $attempts,
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
                'checkout.session.expired' => $this->handleCheckoutExpired($obj),
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
        $sessionId = $session['id'] ?? null;
        if (! $sessionId) {
            Log::warning('stripe.checkout_completed_missing_session_id', [
                'purchase_id' => $session['client_reference_id'] ?? null,
            ]);

            return;
        }

        if (! array_key_exists('amount_total', $session) || $session['amount_total'] === null) {
            Log::warning('stripe.checkout_completed_missing_amount_total', [
                'session_id' => $sessionId,
                'purchase_id' => $session['client_reference_id'] ?? null,
            ]);

            // Fail closed: never mark paid without a verified Stripe amount.
            return;
        }

        $stripeTotal = (int) $session['amount_total'];
        $attempt = $this->attempts->findByStripeSession($sessionId);

        // Legacy / missing attempt: fail safe — never settle without an immutable attempt.
        if (! $attempt) {
            Log::warning('stripe.checkout_completed_unknown_attempt', [
                'session_id' => $sessionId,
                'purchase_id' => $session['client_reference_id'] ?? null,
            ]);

            return;
        }

        if (in_array($attempt->status, [
            PurchasePaymentAttempt::STATUS_SUPERSEDED,
            PurchasePaymentAttempt::STATUS_CANCELLED,
            PurchasePaymentAttempt::STATUS_EXPIRED,
        ], true)) {
            Log::warning('stripe.checkout_completed_stale_attempt', [
                'session_id' => $sessionId,
                'attempt_id' => $attempt->id,
                'status' => $attempt->status,
            ]);

            // Absorb the event without settling — old session cannot pay updated terms.
            return;
        }

        try {
            $this->creditCheckout->completeFromAttempt(
                attempt: $attempt,
                stripeAmountTotalMinor: $stripeTotal,
                paymentIntentId: isset($session['payment_intent']) ? (string) $session['payment_intent'] : null,
                sessionId: $sessionId,
            );
        } catch (RuntimeException $e) {
            Log::warning('stripe.checkout_completed_rejected', [
                'session_id' => $sessionId,
                'attempt_id' => $attempt->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $purchase = Purchase::find($attempt->purchase_id);
        if (! $purchase) {
            return;
        }

        // Fulfilment + conversion for newly paid purchases (idempotent helpers).
        if ($purchase->fresh()->status === 'paid') {
            $this->fulfilment->markPaymentReceived($purchase);
            $purchase->refresh();
            $this->conversions->createPendingFromPurchase($purchase);

            Notification::route('mail', (string) config('mail.admin_address', config('mail.from.address')))
                ->notify(new AdminOrderReceivedNotification($purchase));

            $this->buyerNotifier->sendPaymentReceived($purchase);

            AuditLogger::record(action: 'purchase.paid', subject: $purchase, after: [
                'stripe_session_id' => $sessionId,
                'payment_attempt_id' => $attempt->id,
                'amount_total' => $stripeTotal,
                'account_credit_applied_minor' => $attempt->account_credit_applied_minor,
                'external_amount_minor' => $attempt->external_amount_minor,
            ]);
        }
    }

    private function handleCheckoutExpired(array $session): void
    {
        $sessionId = $session['id'] ?? null;
        if ($sessionId) {
            $attempt = $this->attempts->findByStripeSession($sessionId);
            if ($attempt) {
                $this->attempts->markExpired($attempt);
            }
        }

        $purchaseId = $session['client_reference_id'] ?? ($session['metadata']['purchase_id'] ?? null);
        if (! $purchaseId) {
            return;
        }
        $purchase = Purchase::find($purchaseId);
        if (! $purchase || $purchase->status !== 'pending') {
            return;
        }

        // Only fail the purchase if no other open attempt remains.
        $hasOpen = PurchasePaymentAttempt::query()
            ->where('purchase_id', $purchase->id)
            ->where('status', PurchasePaymentAttempt::STATUS_OPEN)
            ->exists();

        if (! $hasOpen) {
            $this->reservations->releaseForPurchase($purchase, null, 'expired');
            $purchase->update(['status' => 'failed']);
            AuditLogger::record(action: 'purchase.checkout_expired', subject: $purchase);
        }
    }

    private function handlePaymentSucceeded(array $pi): void
    {
        Purchase::where('stripe_payment_intent_id', $pi['id'] ?? '__none__')
            ->update(['stripe_charge_id' => $pi['latest_charge'] ?? null]);
    }

    private function handlePaymentFailed(array $pi): void
    {
        $purchase = Purchase::where('stripe_payment_intent_id', $pi['id'] ?? '__none__')
            ->where('status', 'pending')
            ->first();

        if ($purchase) {
            $attemptId = $purchase->active_payment_attempt_id;
            if ($attemptId) {
                $attempt = PurchasePaymentAttempt::query()->find($attemptId);
                if ($attempt) {
                    $this->attempts->markExpired($attempt);
                }
            }
            $this->reservations->releaseForPurchase($purchase, null, 'payment_failed');
            $purchase->update(['status' => 'failed']);
        }
    }

    private function handleRefund(array $charge): void
    {
        $purchase = Purchase::where('stripe_charge_id', $charge['id'] ?? null)
            ->orWhere('stripe_payment_intent_id', $charge['payment_intent'] ?? '__none__')
            ->first();

        if (! $purchase || ! in_array($purchase->status, ['paid', 'refunded'], true)) {
            return;
        }

        // Prefer Stripe cumulative amount_refunded when present.
        $cumulative = isset($charge['amount_refunded'])
            ? (int) $charge['amount_refunded']
            : (int) ($charge['amount'] ?? 0);

        $externalPaid = (int) ($purchase->external_amount_minor ?? 0);
        $wasPaid = $purchase->status === 'paid';

        // Mark purchase refunded only once the full package value is refunded
        // (external + any AC portion implied by cumulative covering package),
        // or when cumulative covers at least the external paid portion and
        // remaining is handled via AC restoration. For ops simplicity: mark
        // refunded when cumulative >= package amount OR when cumulative >=
        // external and AC restoration reaches full AC spent.
        $this->creditCheckout->restoreAfterExternalRefund($purchase, $cumulative);
        $purchase->refresh();

        $acSpent = (int) $purchase->account_credit_applied_minor;
        $fullyCovered = $cumulative >= ((int) $purchase->amount_minor)
            || ($cumulative >= $externalPaid && (int) $purchase->account_credit_restored_minor >= $acSpent);

        if ($wasPaid && $fullyCovered) {
            $purchase->update(['status' => 'refunded']);
            try {
                $this->fulfilment->transition($purchase, OrderStatus::Refunded, restoreCredit: false);
            } catch (\DomainException) {
                // already transitioned
            }
            $this->conversions->reverseByPurchase($purchase, 'refund');
        }

        AuditLogger::record(action: 'purchase.refunded', subject: $purchase, after: [
            'cumulative_external_refunded_minor' => $cumulative,
            'account_credit_restored_minor' => $purchase->account_credit_restored_minor,
        ]);
    }

    private function handleDispute(array $dispute): void
    {
        $purchase = Purchase::where('stripe_charge_id', $dispute['charge'] ?? null)->first();
        if (! $purchase) {
            return;
        }
        $purchase->update(['status' => 'chargeback']);
        try {
            $this->fulfilment->transition($purchase, OrderStatus::Refunded, restoreCredit: false);
        } catch (\DomainException) {
            // Ignore illegal transitions from terminal states.
        }
        // Dispute: restore AC for the full external+AC mix (treat as full clawback of card portion first).
        $externalPaid = (int) ($purchase->external_amount_minor ?? 0);
        $acSpent = (int) ($purchase->account_credit_applied_minor ?? 0);
        $this->creditCheckout->restoreAfterExternalRefund($purchase, $externalPaid + $acSpent);
        $this->conversions->reverseByPurchase($purchase, 'chargeback');
        if ($purchase->ambassador_profile_id_snapshot) {
            AmbassadorProfile::where('id', $purchase->ambassador_profile_id_snapshot)
                ->update(['flagged_for_review' => true, 'flagged_reason' => 'chargeback']);
        }
        AuditLogger::record(action: 'purchase.chargeback', subject: $purchase);
    }
}
