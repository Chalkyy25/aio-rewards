<?php

namespace App\Domain\Credits;

use App\Models\AccountCreditBalance;
use App\Models\AccountCreditReservation;
use App\Models\AccountCreditTransaction;
use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Append-only Account Credit ledger. Balance cache is updated in the
 * same DB transaction as each ledger insert.
 *
 * Money is always integer minor units. Never floats.
 *
 * Available balance = ledger balance − sum(pending non-expired reservations).
 */
class AccountCreditLedger
{
    public function balanceMinor(AmbassadorProfile|int $profile): int
    {
        $profileId = $profile instanceof AmbassadorProfile ? $profile->id : $profile;

        $cached = AccountCreditBalance::query()
            ->where('ambassador_profile_id', $profileId)
            ->value('balance_minor');

        if ($cached !== null) {
            return (int) $cached;
        }

        return (int) AccountCreditTransaction::query()
            ->where('ambassador_profile_id', $profileId)
            ->sum('amount_minor');
    }

    /** Sum of active (pending, non-expired) reservations. */
    public function reservedMinor(AmbassadorProfile|int $profile): int
    {
        $profileId = $profile instanceof AmbassadorProfile ? $profile->id : $profile;

        return (int) AccountCreditReservation::query()
            ->where('ambassador_profile_id', $profileId)
            ->where('status', AccountCreditReservation::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->sum('amount_minor');
    }

    /** Spendable balance after active reservations. */
    public function availableMinor(AmbassadorProfile|int $profile): int
    {
        return max(0, $this->balanceMinor($profile) - $this->reservedMinor($profile));
    }

    /**
     * Post a credit (positive) or debit (negative) ledger entry.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException when a conflicting idempotency key already exists
     */
    public function post(
        AmbassadorProfile $profile,
        int $signedAmountMinor,
        string $currency,
        string $source,
        string $idempotencyKey,
        ?User $actor = null,
        ?int $rewardId = null,
        ?string $purchaseId = null,
        string $origin = 'system',
        ?string $reference = null,
        ?string $note = null,
    ): AccountCreditTransaction {
        if ($signedAmountMinor === 0) {
            throw new InvalidArgumentException('Account Credit amount must be non-zero.');
        }

        $direction = $signedAmountMinor > 0
            ? AccountCreditTransaction::DIRECTION_CREDIT
            : AccountCreditTransaction::DIRECTION_DEBIT;

        $existing = AccountCreditTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $profile,
                $signedAmountMinor,
                $currency,
                $source,
                $idempotencyKey,
                $actor,
                $rewardId,
                $purchaseId,
                $origin,
                $reference,
                $note,
                $direction,
            ) {
                // Serialize balance updates per member.
                $balance = AccountCreditBalance::query()
                    ->where('ambassador_profile_id', $profile->id)
                    ->lockForUpdate()
                    ->first();

                if (! $balance) {
                    $balance = AccountCreditBalance::create([
                        'ambassador_profile_id' => $profile->id,
                        'balance_minor' => 0,
                        'currency' => strtolower($currency),
                    ]);
                    $balance = AccountCreditBalance::query()
                        ->whereKey($balance->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $next = $balance->balance_minor + $signedAmountMinor;
                if ($next < 0) {
                    throw new InvalidArgumentException('Insufficient Account Credit balance.');
                }

                $tx = AccountCreditTransaction::create([
                    'ambassador_profile_id' => $profile->id,
                    'amount_minor' => $signedAmountMinor,
                    'currency' => strtolower($currency),
                    'direction' => $direction,
                    'source' => $source,
                    'reward_id' => $rewardId,
                    'purchase_id' => $purchaseId,
                    'actor_user_id' => $actor?->getKey(),
                    'origin' => $origin,
                    'idempotency_key' => $idempotencyKey,
                    'reference' => $reference,
                    'note' => $note,
                    'created_at' => now(),
                ]);

                $balance->update([
                    'balance_minor' => $next,
                    'currency' => strtolower($currency),
                ]);

                AuditLogger::record(
                    action: 'account_credit.posted',
                    subject: $tx,
                    actor: $actor,
                    after: [
                        'amount_minor' => $signedAmountMinor,
                        'direction' => $direction,
                        'source' => $source,
                        'balance_minor' => $next,
                        'reward_id' => $rewardId,
                        'purchase_id' => $purchaseId,
                    ],
                );

                return $tx;
            }, attempts: 3);
        } catch (QueryException $e) {
            // Unique race on idempotency_key or reward+source / purchase+source.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains(strtolower($e->getMessage()), 'unique')) {
                $existing = AccountCreditTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    public function creditRewardFulfilment(
        AmbassadorProfile $profile,
        int $amountMinor,
        string $currency,
        int $rewardId,
        ?User $actor = null,
        ?string $note = null,
    ): AccountCreditTransaction {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Reward credit amount must be positive.');
        }

        return $this->post(
            profile: $profile,
            signedAmountMinor: $amountMinor,
            currency: $currency,
            source: AccountCreditTransaction::SOURCE_REWARD_FULFILMENT,
            idempotencyKey: 'reward_credit:'.$rewardId,
            actor: $actor,
            rewardId: $rewardId,
            origin: $actor ? 'admin' : 'system',
            reference: 'reward:'.$rewardId,
            note: $note,
        );
    }

    public function creditRewardBonus(
        AmbassadorProfile $profile,
        int $amountMinor,
        string $currency,
        int $rewardId,
        ?User $actor = null,
        ?string $note = null,
    ): AccountCreditTransaction {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Reward bonus amount must be positive.');
        }

        return $this->post(
            profile: $profile,
            signedAmountMinor: $amountMinor,
            currency: $currency,
            source: AccountCreditTransaction::SOURCE_REWARD_BONUS,
            idempotencyKey: 'reward_bonus:'.$rewardId,
            actor: $actor,
            rewardId: $rewardId,
            origin: $actor ? 'admin' : 'system',
            reference: 'reward_bonus:'.$rewardId,
            note: $note ?? 'Milestone Account Credit Bonus',
        );
    }

    public function debitPurchaseRedemption(
        AmbassadorProfile $profile,
        int $amountMinor,
        string $currency,
        string $purchaseId,
        ?User $actor = null,
        ?string $note = null,
    ): AccountCreditTransaction {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Purchase redemption amount must be positive.');
        }

        return $this->post(
            profile: $profile,
            signedAmountMinor: -$amountMinor,
            currency: $currency,
            source: AccountCreditTransaction::SOURCE_PURCHASE_REDEMPTION,
            idempotencyKey: 'purchase_redemption:'.$purchaseId,
            actor: $actor,
            purchaseId: $purchaseId,
            origin: $actor ? 'member' : 'system',
            reference: 'purchase:'.$purchaseId,
            note: $note ?? 'Package Purchase',
        );
    }

    public function creditRestoration(
        AmbassadorProfile $profile,
        int $amountMinor,
        string $currency,
        string $purchaseId,
        ?User $actor = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): AccountCreditTransaction {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Credit restoration amount must be positive.');
        }

        return $this->post(
            profile: $profile,
            signedAmountMinor: $amountMinor,
            currency: $currency,
            source: AccountCreditTransaction::SOURCE_CREDIT_RESTORATION,
            idempotencyKey: $idempotencyKey ?? ('credit_restoration:'.$purchaseId),
            actor: $actor,
            purchaseId: $purchaseId,
            origin: $actor ? 'admin' : 'system',
            reference: 'purchase:'.$purchaseId,
            note: $note ?? 'Credit Restoration',
        );
    }
}
