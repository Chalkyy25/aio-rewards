<?php

namespace App\Domain\Credits;

use App\Domain\Billing\PurchasePaymentAttemptService;
use App\Domain\Billing\StripeCheckoutService;
use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Notifications\BuyerOrderNotifier;
use App\Domain\Referrals\ConversionService;
use App\Models\AccountCreditReservation;
use App\Models\AccountCreditTransaction;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\PurchasePaymentAttempt;
use App\Models\User;
use App\Notifications\AdminOrderReceivedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Checkout\Session as StripeSession;

/**
 * Apply optional Account Credit toward a package purchase using the existing
 * Purchase + Stripe checkout architecture.
 *
 * All money math is server-side. Client may only opt in/out of using credit.
 * Each Stripe session is bound to an immutable PurchasePaymentAttempt.
 */
class AccountCreditCheckoutService
{
    public function __construct(
        private readonly AccountCreditLedger $ledger,
        private readonly AccountCreditReservationService $reservations,
        private readonly PurchasePaymentAttemptService $attempts,
        private readonly StripeCheckoutService $stripe,
        private readonly OrderFulfilmentService $fulfilment,
        private readonly ConversionService $conversions,
        private readonly BuyerOrderNotifier $buyerNotifier,
    ) {}

    /**
     * @return array{credit_applied_minor: int, external_amount_minor: int, available_minor: int}
     */
    public function quote(AmbassadorProfile $profile, int $packageAmountMinor, bool $useCredit): array
    {
        $available = $this->ledger->availableMinor($profile);
        if (! $useCredit || $available <= 0 || $packageAmountMinor <= 0) {
            return [
                'credit_applied_minor' => 0,
                'external_amount_minor' => $packageAmountMinor,
                'available_minor' => $available,
            ];
        }

        $applied = min($available, $packageAmountMinor);

        return [
            'credit_applied_minor' => $applied,
            'external_amount_minor' => $packageAmountMinor - $applied,
            'available_minor' => $available,
        ];
    }

    /**
     * @return array{purchase: Purchase, stripe_session: ?StripeSession, fully_credited: bool, attempt: ?PurchasePaymentAttempt}
     */
    public function beginCheckout(
        Purchase $purchase,
        Package $package,
        ?AmbassadorProfile $profile,
        bool $useCredit,
        Request $request,
        ?User $actor = null,
    ): array {
        $packageAmount = (int) $package->amount_minor;

        if ($useCredit && $profile) {
            $quote = $this->quote($profile, $packageAmount, true);
        } else {
            $quote = [
                'credit_applied_minor' => 0,
                'external_amount_minor' => $packageAmount,
                'available_minor' => 0,
            ];
        }

        $creditApplied = $quote['credit_applied_minor'];
        $external = $quote['external_amount_minor'];

        return DB::transaction(function () use (
            $purchase,
            $package,
            $profile,
            $creditApplied,
            $external,
            $packageAmount,
            $request,
            $actor,
        ) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException('Purchase is not pending.');
            }

            // Invalidate any live Stripe sessions / reservations before new terms.
            $this->attempts->invalidateOpenAttempts($locked, $actor, 'payment_mix_changed');
            $locked->refresh();

            $locked->forceFill([
                'amount_minor' => $packageAmount,
                'account_credit_applied_minor' => $creditApplied,
                'external_amount_minor' => $external,
            ])->save();

            if ($creditApplied > 0) {
                if (! $profile) {
                    throw new InvalidArgumentException('Account Credit requires an authenticated Rewards Member.');
                }
                $this->reservations->reserve(
                    profile: $profile,
                    purchase: $locked,
                    amountMinor: $creditApplied,
                    currency: $locked->currency,
                    actor: $actor,
                );
            }

            if ($external === 0) {
                $this->completeFullyCredited($locked, $profile, $actor);

                return [
                    'purchase' => $locked->fresh(),
                    'stripe_session' => null,
                    'fully_credited' => true,
                    'attempt' => null,
                ];
            }

            if (! StripeCheckoutService::isConfigured()) {
                if ($creditApplied > 0) {
                    $this->reservations->releaseForPurchase($locked, $actor, 'stripe_unconfigured');
                }
                throw new RuntimeException('Stripe is not configured on this environment.');
            }

            $attempt = $this->attempts->openAttempt(
                purchase: $locked,
                packageAmountMinor: $packageAmount,
                creditAppliedMinor: $creditApplied,
                externalAmountMinor: $external,
                currency: $locked->currency,
            );

            $session = $this->stripe->createSession($locked, $package, $request, $attempt);
            $this->attempts->attachStripeSession($attempt, $session->id);

            return [
                'purchase' => $locked->fresh(),
                'stripe_session' => $session,
                'fully_credited' => false,
                'attempt' => $attempt->fresh(),
            ];
        }, attempts: 3);
    }

    public function completeFullyCredited(Purchase $purchase, ?AmbassadorProfile $profile, ?User $actor = null): void
    {
        DB::transaction(function () use ($purchase, $profile, $actor) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return;
            }
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException('Purchase cannot be completed.');
            }

            $applied = (int) $locked->account_credit_applied_minor;
            if ($applied <= 0 || (int) $locked->external_amount_minor !== 0) {
                throw new InvalidArgumentException('Purchase is not fully Account-Credit funded.');
            }
            if (! $profile) {
                throw new InvalidArgumentException('Missing member profile for Account Credit completion.');
            }

            $reservation = $locked->accountCreditReservation;
            if (! $reservation || $reservation->status !== AccountCreditReservation::STATUS_PENDING) {
                $reservation = $this->reservations->reserve(
                    profile: $profile,
                    purchase: $locked,
                    amountMinor: $applied,
                    currency: $locked->currency,
                    actor: $actor,
                );
            }

            $this->reservations->commit($reservation, $actor);

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $this->fulfilment->markPaymentReceived($locked);
            $locked->refresh();

            $this->conversions->createPendingFromPurchase($locked);

            Notification::route('mail', (string) config('mail.admin_address', config('mail.from.address')))
                ->notify(new AdminOrderReceivedNotification($locked));

            $this->buyerNotifier->sendPaymentReceived($locked);

            AuditLogger::record(action: 'purchase.paid_account_credit', subject: $locked, actor: $actor, after: [
                'account_credit_applied_minor' => $applied,
                'external_amount_minor' => 0,
            ]);
        }, attempts: 3);
    }

    /**
     * Complete purchase from a verified payment attempt after Stripe success.
     */
    public function completeFromAttempt(
        PurchasePaymentAttempt $attempt,
        int $stripeAmountTotalMinor,
        ?string $paymentIntentId = null,
        ?string $sessionId = null,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($attempt, $stripeAmountTotalMinor, $paymentIntentId, $sessionId, $actor) {
            /** @var PurchasePaymentAttempt $lockedAttempt */
            $lockedAttempt = PurchasePaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            $this->attempts->assertOpenAndAmountMatches($lockedAttempt, $stripeAmountTotalMinor);

            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($lockedAttempt->purchase_id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                if ($lockedAttempt->status === PurchasePaymentAttempt::STATUS_OPEN) {
                    $this->attempts->markCompleted($lockedAttempt);
                }
                $this->ensureReservationCommittedForAttempt($locked, $lockedAttempt, $actor);

                return;
            }

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Purchase is not pending and cannot be settled by this attempt.');
            }

            if ($lockedAttempt->status === PurchasePaymentAttempt::STATUS_OPEN) {
                // Align purchase columns to the immutable attempt snapshot before commit.
                $locked->forceFill([
                    'amount_minor' => $lockedAttempt->package_amount_minor,
                    'account_credit_applied_minor' => $lockedAttempt->account_credit_applied_minor,
                    'external_amount_minor' => $lockedAttempt->external_amount_minor,
                    'stripe_session_id' => $sessionId ?? $lockedAttempt->stripe_session_id,
                    'stripe_payment_intent_id' => $paymentIntentId ?? $locked->stripe_payment_intent_id,
                    'active_payment_attempt_id' => $lockedAttempt->id,
                ])->save();

                $this->ensureReservationCommittedForAttempt($locked, $lockedAttempt, $actor);

                $locked->update([
                    'status' => 'paid',
                    'paid_at' => $locked->paid_at ?? now(),
                ]);

                $this->attempts->markCompleted($lockedAttempt);
            }
        }, attempts: 3);
    }

    private function ensureReservationCommittedForAttempt(
        Purchase $purchase,
        PurchasePaymentAttempt $attempt,
        ?User $actor,
    ): void {
        $applied = (int) $attempt->account_credit_applied_minor;
        if ($applied <= 0) {
            return;
        }

        $reservation = $purchase->accountCreditReservation;
        if (! $reservation) {
            throw new RuntimeException('Missing Account Credit reservation for partial purchase.');
        }

        if ($reservation->status === AccountCreditReservation::STATUS_COMMITTED) {
            return;
        }

        if ((int) $reservation->amount_minor !== $applied) {
            throw new RuntimeException('Reservation amount does not match payment attempt credit snapshot.');
        }

        $this->reservations->commit($reservation, $actor);
    }

    /**
     * Amount-aware Account Credit restoration.
     *
     * Policy: Stripe refunds apply to the external (card) portion first.
     * AC restoration = max(0, cumulative_external_refunded - external_paid),
     * capped at AC actually spent, restored incrementally and idempotently.
     *
     * @return ?AccountCreditTransaction the delta restoration posted (if any)
     */
    public function restoreAfterExternalRefund(
        Purchase $purchase,
        int $cumulativeExternalRefundedMinor,
        ?User $actor = null,
    ): ?AccountCreditTransaction {
        return DB::transaction(function () use ($purchase, $cumulativeExternalRefundedMinor, $actor) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            $debit = AccountCreditTransaction::query()
                ->where('purchase_id', $locked->id)
                ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)
                ->where('direction', AccountCreditTransaction::DIRECTION_DEBIT)
                ->first();

            if (! $debit) {
                $this->reservations->releaseForPurchase($locked, $actor, 'refund_no_debit');
                $locked->update([
                    'external_refunded_minor' => max(
                        (int) $locked->external_refunded_minor,
                        $cumulativeExternalRefundedMinor,
                    ),
                ]);

                return null;
            }

            $acSpent = abs((int) $debit->amount_minor);
            $externalPaid = (int) ($locked->external_amount_minor ?? 0);
            $alreadyRestored = (int) $locked->account_credit_restored_minor;

            $cumulative = max((int) $locked->external_refunded_minor, $cumulativeExternalRefundedMinor);
            $targetRestore = min($acSpent, max(0, $cumulative - $externalPaid));
            $delta = $targetRestore - $alreadyRestored;

            $locked->update(['external_refunded_minor' => $cumulative]);

            if ($delta <= 0) {
                return null;
            }

            $profile = AmbassadorProfile::query()->find($debit->ambassador_profile_id);
            if (! $profile) {
                throw new RuntimeException('Ambassador profile missing for credit restoration.');
            }

            $tx = $this->ledger->creditRestoration(
                profile: $profile,
                amountMinor: $delta,
                currency: $debit->currency,
                purchaseId: $locked->id,
                actor: $actor,
                idempotencyKey: 'credit_restoration:'.$locked->id.':to:'.$targetRestore,
            );

            $locked->update(['account_credit_restored_minor' => $targetRestore]);

            return $tx;
        }, attempts: 3);
    }

    /**
     * Full AC-funded purchase: restore the full debit (idempotent).
     */
    public function restoreFullyCreditedPurchase(Purchase $purchase, ?User $actor = null): ?AccountCreditTransaction
    {
        $debit = AccountCreditTransaction::query()
            ->where('purchase_id', $purchase->id)
            ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)
            ->first();

        if (! $debit) {
            return null;
        }

        $acSpent = abs((int) $debit->amount_minor);
        $externalPaid = (int) ($purchase->external_amount_minor ?? 0);

        // cumulative >= external + ac → full AC restoration under the allocation policy.
        return $this->restoreAfterExternalRefund(
            purchase: $purchase,
            cumulativeExternalRefundedMinor: $externalPaid + $acSpent,
            actor: $actor,
        );
    }
}
