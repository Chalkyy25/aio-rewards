<?php

namespace App\Domain\Credits;

use App\Models\AccountCreditReservation;
use App\Models\AccountCreditTransaction;
use App\Models\AmbassadorProfile;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Soft-hold Account Credit against a purchase until Stripe succeeds (or full-credit commit).
 *
 * ACCOUNT CREDIT CAN NEVER BE SPENT TWICE:
 *  - available = ledger − pending reservations
 *  - reserve under profile row lock
 *  - commit creates immutable purchase_redemption debit then marks reservation committed
 *  - release/expiry frees the hold without ledger debit
 */
class AccountCreditReservationService
{
    public function __construct(
        private readonly AccountCreditLedger $ledger,
    ) {}

    /**
     * Reserve up to $amountMinor of available credit for $purchase.
     *
     * @throws InvalidArgumentException when insufficient available balance
     */
    public function reserve(
        AmbassadorProfile $profile,
        Purchase $purchase,
        int $amountMinor,
        string $currency,
        ?User $actor = null,
        ?int $ttlMinutes = 60,
    ): AccountCreditReservation {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Reservation amount must be positive.');
        }

        $key = 'credit_reserve:'.$purchase->id;

        $existing = AccountCreditReservation::query()
            ->where('idempotency_key', $key)
            ->first();
        if ($existing) {
            if ($existing->status === AccountCreditReservation::STATUS_PENDING && $existing->isPending()) {
                return $existing;
            }
            if ($existing->status === AccountCreditReservation::STATUS_COMMITTED) {
                return $existing;
            }
            // Released/expired — allow a fresh reserve by updating the same purchase row
            // (unique on purchase_id). Fall through under lock.
        }

        try {
            return DB::transaction(function () use ($profile, $purchase, $amountMinor, $currency, $actor, $ttlMinutes, $key) {
                AmbassadorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();

                $row = AccountCreditReservation::query()
                    ->where('purchase_id', $purchase->id)
                    ->lockForUpdate()
                    ->first();

                if ($row && $row->status === AccountCreditReservation::STATUS_PENDING && $row->isPending()) {
                    return $row;
                }
                if ($row && $row->status === AccountCreditReservation::STATUS_COMMITTED) {
                    return $row;
                }

                $available = $this->ledger->availableMinor($profile);
                // If reusing a released row, its amount is no longer counted in available.
                if ($available < $amountMinor) {
                    throw new InvalidArgumentException('Insufficient available Account Credit.');
                }

                $expiresAt = $ttlMinutes !== null ? now()->addMinutes($ttlMinutes) : null;

                if ($row) {
                    $row->update([
                        'ambassador_profile_id' => $profile->id,
                        'amount_minor' => $amountMinor,
                        'currency' => strtolower($currency),
                        'status' => AccountCreditReservation::STATUS_PENDING,
                        'expires_at' => $expiresAt,
                        'committed_at' => null,
                        'released_at' => null,
                        'idempotency_key' => $key,
                    ]);
                    $reservation = $row->fresh();
                } else {
                    $reservation = AccountCreditReservation::create([
                        'ambassador_profile_id' => $profile->id,
                        'purchase_id' => $purchase->id,
                        'amount_minor' => $amountMinor,
                        'currency' => strtolower($currency),
                        'status' => AccountCreditReservation::STATUS_PENDING,
                        'expires_at' => $expiresAt,
                        'idempotency_key' => $key,
                    ]);
                }

                AuditLogger::record(
                    action: 'account_credit.reserved',
                    subject: $reservation,
                    actor: $actor,
                    after: [
                        'amount_minor' => $amountMinor,
                        'purchase_id' => $purchase->id,
                        'available_after' => $this->ledger->availableMinor($profile),
                    ],
                );

                return $reservation;
            }, attempts: 3);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains(strtolower($e->getMessage()), 'unique')) {
                $again = AccountCreditReservation::query()->where('idempotency_key', $key)->first();
                if ($again) {
                    return $again;
                }
            }
            throw $e;
        }
    }

    /**
     * Commit a pending reservation into an immutable purchase_redemption debit.
     */
    public function commit(AccountCreditReservation $reservation, ?User $actor = null): AccountCreditTransaction
    {
        return DB::transaction(function () use ($reservation, $actor) {
            /** @var AccountCreditReservation|null $locked */
            $locked = AccountCreditReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new RuntimeException('Reservation missing.');
            }

            if ($locked->status === AccountCreditReservation::STATUS_COMMITTED) {
                $existing = AccountCreditTransaction::query()
                    ->where('purchase_id', $locked->purchase_id)
                    ->where('source', AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION)
                    ->first();
                if ($existing) {
                    return $existing;
                }
                throw new RuntimeException('Reservation committed without redemption debit.');
            }

            if ($locked->status !== AccountCreditReservation::STATUS_PENDING) {
                throw new InvalidArgumentException('Reservation is not pending.');
            }

            if ($locked->expires_at && $locked->expires_at->isPast()) {
                $locked->update([
                    'status' => AccountCreditReservation::STATUS_EXPIRED,
                    'released_at' => now(),
                ]);
                throw new InvalidArgumentException('Reservation has expired.');
            }

            $profile = AmbassadorProfile::query()->whereKey($locked->ambassador_profile_id)->lockForUpdate()->firstOrFail();

            $tx = $this->ledger->debitPurchaseRedemption(
                profile: $profile,
                amountMinor: (int) $locked->amount_minor,
                currency: $locked->currency,
                purchaseId: $locked->purchase_id,
                actor: $actor,
            );

            $locked->update([
                'status' => AccountCreditReservation::STATUS_COMMITTED,
                'committed_at' => now(),
            ]);

            AuditLogger::record(
                action: 'account_credit.reservation_committed',
                subject: $locked->fresh(),
                actor: $actor,
                after: [
                    'transaction_id' => $tx->id,
                    'amount_minor' => $locked->amount_minor,
                ],
            );

            return $tx;
        }, attempts: 3);
    }

    public function release(AccountCreditReservation $reservation, ?User $actor = null, string $reason = 'released'): bool
    {
        return (bool) DB::transaction(function () use ($reservation, $actor, $reason) {
            /** @var AccountCreditReservation|null $locked */
            $locked = AccountCreditReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            if ($locked->status === AccountCreditReservation::STATUS_COMMITTED) {
                return false;
            }

            if (in_array($locked->status, [
                AccountCreditReservation::STATUS_RELEASED,
                AccountCreditReservation::STATUS_EXPIRED,
            ], true)) {
                return true;
            }

            $status = $reason === 'expired'
                ? AccountCreditReservation::STATUS_EXPIRED
                : AccountCreditReservation::STATUS_RELEASED;

            $locked->update([
                'status' => $status,
                'released_at' => now(),
            ]);

            AuditLogger::record(
                action: 'account_credit.reservation_released',
                subject: $locked->fresh(),
                actor: $actor,
                after: ['reason' => $reason, 'amount_minor' => $locked->amount_minor],
            );

            return true;
        }, attempts: 3);
    }

    public function releaseForPurchase(Purchase $purchase, ?User $actor = null, string $reason = 'cancelled'): bool
    {
        $reservation = AccountCreditReservation::query()
            ->where('purchase_id', $purchase->id)
            ->where('status', AccountCreditReservation::STATUS_PENDING)
            ->first();

        if (! $reservation) {
            return false;
        }

        return $this->release($reservation, $actor, $reason);
    }

    public function expireStale(): int
    {
        $stale = AccountCreditReservation::query()
            ->where('status', AccountCreditReservation::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($stale as $reservation) {
            if ($this->release($reservation, null, 'expired')) {
                $count++;
            }
        }

        return $count;
    }
}
