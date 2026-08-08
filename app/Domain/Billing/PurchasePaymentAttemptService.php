<?php

namespace App\Domain\Billing;

use App\Domain\Credits\AccountCreditReservationService;
use App\Models\Purchase;
use App\Models\PurchasePaymentAttempt;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

/**
 * Binds each Stripe Checkout Session to an immutable pricing snapshot.
 *
 * Webhooks must reconcile against the attempt for that session id — never
 * against mutable purchase columns alone.
 */
class PurchasePaymentAttemptService
{
    public function __construct(
        private readonly AccountCreditReservationService $reservations,
    ) {}

    /**
     * Supersede any open attempts, release reservations, and clear live session
     * before starting a new payment mix on the same purchase.
     */
    public function invalidateOpenAttempts(Purchase $purchase, ?User $actor = null, string $reason = 'superseded'): void
    {
        DB::transaction(function () use ($purchase, $actor, $reason) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            $open = PurchasePaymentAttempt::query()
                ->where('purchase_id', $locked->id)
                ->where('status', PurchasePaymentAttempt::STATUS_OPEN)
                ->lockForUpdate()
                ->get();

            foreach ($open as $attempt) {
                $this->bestEffortExpireStripeSession($attempt->stripe_session_id);
                $attempt->update([
                    'status' => PurchasePaymentAttempt::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ]);
                AuditLogger::record(
                    action: 'purchase.payment_attempt_superseded',
                    subject: $attempt,
                    actor: $actor,
                    after: ['reason' => $reason, 'stripe_session_id' => $attempt->stripe_session_id],
                );
            }

            $this->reservations->releaseForPurchase($locked, $actor, $reason);

            $locked->update([
                'stripe_session_id' => null,
                'active_payment_attempt_id' => null,
            ]);
        }, attempts: 3);
    }

    /**
     * Create an open attempt with immutable pricing. Does not create Stripe yet.
     */
    public function openAttempt(
        Purchase $purchase,
        int $packageAmountMinor,
        int $creditAppliedMinor,
        int $externalAmountMinor,
        string $currency,
    ): PurchasePaymentAttempt {
        return PurchasePaymentAttempt::create([
            'purchase_id' => $purchase->id,
            'cancel_token' => PurchasePaymentAttempt::makeCancelToken(),
            'package_amount_minor' => $packageAmountMinor,
            'account_credit_applied_minor' => $creditAppliedMinor,
            'external_amount_minor' => $externalAmountMinor,
            'currency' => strtolower($currency),
            'status' => PurchasePaymentAttempt::STATUS_OPEN,
        ]);
    }

    public function attachStripeSession(PurchasePaymentAttempt $attempt, string $sessionId): PurchasePaymentAttempt
    {
        $attempt->update(['stripe_session_id' => $sessionId]);

        Purchase::query()->whereKey($attempt->purchase_id)->update([
            'stripe_session_id' => $sessionId,
            'active_payment_attempt_id' => $attempt->id,
            'account_credit_applied_minor' => $attempt->account_credit_applied_minor,
            'external_amount_minor' => $attempt->external_amount_minor,
            'amount_minor' => $attempt->package_amount_minor,
        ]);

        return $attempt->fresh();
    }

    public function findByStripeSession(string $sessionId): ?PurchasePaymentAttempt
    {
        return PurchasePaymentAttempt::query()
            ->where('stripe_session_id', $sessionId)
            ->first();
    }

    public function markCompleted(PurchasePaymentAttempt $attempt): void
    {
        if ($attempt->status === PurchasePaymentAttempt::STATUS_COMPLETED) {
            return;
        }

        $attempt->update([
            'status' => PurchasePaymentAttempt::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markCancelled(PurchasePaymentAttempt $attempt, ?User $actor = null): bool
    {
        return (bool) DB::transaction(function () use ($attempt, $actor) {
            /** @var PurchasePaymentAttempt|null $locked */
            $locked = PurchasePaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== PurchasePaymentAttempt::STATUS_OPEN) {
                return $locked?->status === PurchasePaymentAttempt::STATUS_CANCELLED;
            }

            $locked->update([
                'status' => PurchasePaymentAttempt::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $purchase = Purchase::query()->whereKey($locked->purchase_id)->lockForUpdate()->first();
            if ($purchase && $purchase->status === 'pending') {
                $this->reservations->releaseForPurchase($purchase, $actor, 'cancelled');
                if ((int) $purchase->active_payment_attempt_id === (int) $locked->id) {
                    $purchase->update(['active_payment_attempt_id' => null]);
                }
            }

            AuditLogger::record(
                action: 'purchase.payment_attempt_cancelled',
                subject: $locked->fresh(),
                actor: $actor,
            );

            return true;
        }, attempts: 3);
    }

    public function markExpired(PurchasePaymentAttempt $attempt): bool
    {
        return (bool) DB::transaction(function () use ($attempt) {
            /** @var PurchasePaymentAttempt|null $locked */
            $locked = PurchasePaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== PurchasePaymentAttempt::STATUS_OPEN) {
                return $locked?->status === PurchasePaymentAttempt::STATUS_EXPIRED;
            }

            $locked->update([
                'status' => PurchasePaymentAttempt::STATUS_EXPIRED,
                'expired_at' => now(),
            ]);

            $purchase = Purchase::query()->whereKey($locked->purchase_id)->lockForUpdate()->first();
            if ($purchase && $purchase->status === 'pending') {
                $this->reservations->releaseForPurchase($purchase, null, 'expired');
                if ((int) $purchase->active_payment_attempt_id === (int) $locked->id) {
                    $purchase->update(['active_payment_attempt_id' => null]);
                }
            }

            return true;
        }, attempts: 3);
    }

    /**
     * Authorize cancel for an attempt: token must match, and if a member owns
     * reserved credit the actor must be that member (or guest with token only
     * when no reservation / no ambassador spend).
     */
    public function authorizeCancel(PurchasePaymentAttempt $attempt, ?User $actor): bool
    {
        if (! $attempt->isOpen()) {
            return false;
        }

        $purchase = $attempt->purchase;
        if (! $purchase || $purchase->status !== 'pending') {
            return false;
        }

        // Token is required and checked by the caller. Additional bind:
        // if credit was applied, only the authenticated owning member may cancel
        // via the member session (token still required). Guests cannot apply credit.
        if ((int) $attempt->account_credit_applied_minor > 0) {
            $profile = $actor?->ambassadorProfile;
            if (! $profile) {
                return false;
            }
            $reservation = $purchase->accountCreditReservation;
            if ($reservation && (int) $reservation->ambassador_profile_id !== (int) $profile->id) {
                return false;
            }
        }

        return true;
    }

    private function bestEffortExpireStripeSession(?string $sessionId): void
    {
        if (! $sessionId || ! StripeCheckoutService::isConfigured()) {
            return;
        }

        try {
            Stripe::setApiKey((string) config('stripe.secret'));
            StripeSession::retrieve($sessionId)->expire();
        } catch (ApiErrorException|\Throwable $e) {
            Log::info('stripe.session_expire_failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function assertOpenAndAmountMatches(PurchasePaymentAttempt $attempt, int $stripeAmountTotal): void
    {
        if ($attempt->status !== PurchasePaymentAttempt::STATUS_OPEN
            && $attempt->status !== PurchasePaymentAttempt::STATUS_COMPLETED) {
            throw new RuntimeException(sprintf(
                'Payment attempt %d is %s and cannot settle the purchase.',
                $attempt->id,
                $attempt->status,
            ));
        }

        if ((int) $stripeAmountTotal !== (int) $attempt->external_amount_minor) {
            throw new RuntimeException(sprintf(
                'Stripe amount_total mismatch for attempt %d: expected %d got %d',
                $attempt->id,
                $attempt->external_amount_minor,
                $stripeAmountTotal,
            ));
        }
    }
}
