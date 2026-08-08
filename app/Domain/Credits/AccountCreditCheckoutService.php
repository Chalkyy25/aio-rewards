<?php

namespace App\Domain\Credits;

use App\Domain\Billing\StripeCheckoutService;
use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Domain\Notifications\BuyerOrderNotifier;
use App\Domain\Referrals\ConversionService;
use App\Models\AccountCreditReservation;
use App\Models\AccountCreditTransaction;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
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
 */
class AccountCreditCheckoutService
{
    public function __construct(
        private readonly AccountCreditLedger $ledger,
        private readonly AccountCreditReservationService $reservations,
        private readonly StripeCheckoutService $stripe,
        private readonly OrderFulfilmentService $fulfilment,
        private readonly ConversionService $conversions,
        private readonly BuyerOrderNotifier $buyerNotifier,
    ) {}

    /**
     * Server-calculated credit application for a package price.
     *
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
     * Start checkout: optionally reserve credit, then either complete fully on credit
     * or create a Stripe session for the remainder.
     *
     * @return array{purchase: Purchase, stripe_session: ?StripeSession, fully_credited: bool}
     */
    public function beginCheckout(
        Purchase $purchase,
        Package $package,
        AmbassadorProfile $profile,
        bool $useCredit,
        Request $request,
        ?User $actor = null,
    ): array {
        // Never trust client money figures — recompute from package + available balance.
        $quote = $this->quote($profile, (int) $package->amount_minor, $useCredit);
        $creditApplied = $quote['credit_applied_minor'];
        $external = $quote['external_amount_minor'];

        return DB::transaction(function () use ($purchase, $package, $profile, $creditApplied, $external, $request, $actor) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException('Purchase is not pending.');
            }

            // Ensure package price is authoritative.
            if ((int) $locked->amount_minor !== (int) $package->amount_minor) {
                $locked->amount_minor = (int) $package->amount_minor;
            }

            $locked->account_credit_applied_minor = $creditApplied;
            $locked->external_amount_minor = $external;
            $locked->save();

            if ($creditApplied > 0) {
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
                ];
            }

            if (! StripeCheckoutService::isConfigured()) {
                if ($creditApplied > 0) {
                    $this->reservations->releaseForPurchase($locked, $actor, 'stripe_unconfigured');
                }
                throw new RuntimeException('Stripe is not configured on this environment.');
            }

            $session = $this->stripe->createSession($locked, $package, $request, $external);
            $locked->update(['stripe_session_id' => $session->id]);

            return [
                'purchase' => $locked->fresh(),
                'stripe_session' => $session,
                'fully_credited' => false,
            ];
        }, attempts: 3);
    }

    /**
     * Full Account Credit purchase — no Stripe.
     */
    public function completeFullyCredited(Purchase $purchase, AmbassadorProfile $profile, ?User $actor = null): void
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

            $reservation = $locked->accountCreditReservation;
            if (! $reservation || $reservation->status !== AccountCreditReservation::STATUS_PENDING) {
                // Ensure a reservation exists then commit.
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
     * After Stripe success: verify charged amount, commit reservation, mark paid.
     * Called from StripeEventProcessor (idempotent).
     */
    public function completeAfterStripe(Purchase $purchase, int $stripeAmountTotalMinor, ?User $actor = null): void
    {
        DB::transaction(function () use ($purchase, $stripeAmountTotalMinor, $actor) {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                // Still ensure reservation is committed if credit was applied.
                $this->ensureReservationCommitted($locked, $actor);

                return;
            }

            if ($locked->status !== 'pending') {
                return;
            }

            $expectedExternal = $locked->external_amount_minor;
            if ($expectedExternal === null) {
                $expectedExternal = max(0, (int) $locked->amount_minor - (int) $locked->account_credit_applied_minor);
            }

            if ((int) $stripeAmountTotalMinor !== (int) $expectedExternal) {
                throw new RuntimeException(sprintf(
                    'Stripe amount mismatch for purchase %s: expected %d got %d',
                    $locked->id,
                    $expectedExternal,
                    $stripeAmountTotalMinor,
                ));
            }

            $this->ensureReservationCommitted($locked, $actor);

            $locked->update([
                'status' => 'paid',
                'paid_at' => $locked->paid_at ?? now(),
            ]);
        }, attempts: 3);
    }

    private function ensureReservationCommitted(Purchase $purchase, ?User $actor): void
    {
        $applied = (int) $purchase->account_credit_applied_minor;
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

        $this->reservations->commit($reservation, $actor);
    }

    /**
     * Restore Account Credit after a refund — only the amount actually debited.
     * Idempotent via credit_restoration:{purchaseId}.
     */
    public function restoreAfterRefund(Purchase $purchase, ?User $actor = null): ?AccountCreditTransaction
    {
        $debit = AccountCreditTransaction::query()
            ->where('purchase_id', $purchase->id)
            ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)
            ->where('direction', AccountCreditTransaction::DIRECTION_DEBIT)
            ->first();

        if (! $debit) {
            // No credit was spent — release any dangling reservation.
            $this->reservations->releaseForPurchase($purchase, $actor, 'refund_no_debit');

            return null;
        }

        $amount = abs((int) $debit->amount_minor);
        if ($amount <= 0) {
            return null;
        }

        $profile = AmbassadorProfile::query()->find($debit->ambassador_profile_id);
        if (! $profile) {
            throw new RuntimeException('Ambassador profile missing for credit restoration.');
        }

        return $this->ledger->creditRestoration(
            profile: $profile,
            amountMinor: $amount,
            currency: $debit->currency,
            purchaseId: $purchase->id,
            actor: $actor,
        );
    }
}
