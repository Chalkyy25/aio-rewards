<?php

namespace App\Domain\Credits;

use App\Domain\Rewards\Events\RewardPaid;
use App\Domain\Rewards\RewardFundingIntegrityException;
use App\Domain\Rewards\RewardFundingIntegrityService;
use App\Enums\PayoutMethod;
use App\Models\AccountCreditTransaction;
use App\Models\Reward;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomically fulfil an approved reward as Account Credit.
 *
 * Posts:
 *  1. reward_fulfilment  = reward.amount_minor (always)
 *  2. reward_bonus       = reward.account_credit_bonus_minor_snapshot (when > 0)
 *
 * Invariants:
 *  - preferred payout method must be Account Credit for new fulfilments
 *  - reward becomes paid only if required ledger credits commit
 *  - one base + at most one bonus per reward (idempotency_key + unique reward+source)
 *  - uniqueness collisions repair incomplete paid linkage rather than reporting bare success
 *  - failure creating bonus rolls back base credit (same DB transaction)
 *  - funding integrity is re-checked under the reward row lock
 */
class AccountCreditFulfilmentService
{
    public function __construct(
        private readonly AccountCreditLedger $ledger,
        private readonly RewardFundingIntegrityService $funding,
    ) {}

    /**
     * @throws RewardFundingIntegrityException
     * @throws InvalidArgumentException when preferred payout method is not Account Credit
     * @throws RuntimeException
     */
    public function apply(Reward $reward, ?User $actor = null, ?string $note = null): bool
    {
        try {
            return $this->applyInsideTransaction($reward, $actor, $note);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Uniqueness collision: never report success without repairing paid linkage.
            return $this->repairAfterUniqueCollision($reward, $actor, $note);
        }
    }

    /**
     * @throws RewardFundingIntegrityException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function applyInsideTransaction(Reward $reward, ?User $actor, ?string $note): bool
    {
        return (bool) DB::transaction(function () use ($reward, $actor, $note) {
            /** @var Reward|null $locked */
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            $existingBase = $this->findCredit($locked->id, AccountCreditTransaction::SOURCE_REWARD_FULFILMENT);
            $existingBonus = $this->findCredit($locked->id, AccountCreditTransaction::SOURCE_REWARD_BONUS);

            if ($existingBase) {
                return $this->ensurePaidLinkedToCredits($locked, $existingBase, $existingBonus, $actor, $note);
            }

            if ($locked->status === 'paid' && $locked->payment_method === PayoutMethod::AccountCredit->value
                && $locked->account_credit_transaction_id) {
                return true;
            }

            if ($locked->status !== 'approved') {
                return false;
            }

            $this->assertPreferredAccountCredit($locked);
            $this->funding->assertFundable($locked);

            $profile = $locked->ambassadorProfile()->lockForUpdate()->first();
            if (! $profile) {
                throw new RuntimeException('Ambassador profile missing for Account Credit fulfilment.');
            }

            $base = $this->ledger->creditRewardFulfilment(
                profile: $profile,
                amountMinor: $locked->amount_minor,
                currency: $locked->currency,
                rewardId: $locked->id,
                actor: $actor,
                note: $note,
            );

            if (! $this->baseCreditMatchesReward($base, $locked)) {
                throw new RuntimeException('Account Credit ledger row does not match the reward.');
            }

            $bonusMinor = $locked->accountCreditBonusMinor();
            $bonus = null;
            if ($bonusMinor > 0) {
                $bonus = $this->ledger->creditRewardBonus(
                    profile: $profile,
                    amountMinor: $bonusMinor,
                    currency: $locked->currency,
                    rewardId: $locked->id,
                    actor: $actor,
                    note: $note,
                );

                if (! $this->bonusCreditMatchesReward($bonus, $locked)) {
                    throw new RuntimeException('Account Credit bonus ledger row does not match the reward.');
                }
            }

            return $this->markRewardPaidWithCredit($locked, $base, $actor, $note, dispatchEvent: true);
        }, attempts: 3);
    }

    /**
     * Re-enter a locked transaction and repair incomplete fulfilment after a unique race.
     */
    private function repairAfterUniqueCollision(Reward $reward, ?User $actor, ?string $note): bool
    {
        return (bool) DB::transaction(function () use ($reward, $actor, $note) {
            /** @var Reward|null $locked */
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            $existingBase = $this->findCredit($locked->id, AccountCreditTransaction::SOURCE_REWARD_FULFILMENT);
            if (! $existingBase) {
                return false;
            }

            $existingBonus = $this->findCredit($locked->id, AccountCreditTransaction::SOURCE_REWARD_BONUS);

            return $this->ensurePaidLinkedToCredits($locked, $existingBase, $existingBonus, $actor, $note);
        }, attempts: 3);
    }

    private function findCredit(int $rewardId, string $source): ?AccountCreditTransaction
    {
        return AccountCreditTransaction::query()
            ->where('reward_id', $rewardId)
            ->where('source', $source)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Ensure existing fulfilment credit(s) are complete and the reward is paid as Account Credit.
     * Preference is not re-checked here — the credit already exists and must not be left orphaned.
     */
    private function ensurePaidLinkedToCredits(
        Reward $locked,
        AccountCreditTransaction $base,
        ?AccountCreditTransaction $bonus,
        ?User $actor,
        ?string $note,
    ): bool {
        if (! $this->baseCreditMatchesReward($base, $locked)) {
            return false;
        }

        $bonusMinor = $locked->accountCreditBonusMinor();
        if ($bonusMinor > 0) {
            if (! $bonus) {
                // Base exists but required bonus is missing — complete the bonus under lock,
                // then mark paid. Never mark paid while required bonus is absent.
                if ($locked->status === 'paid'
                    && $locked->payment_method !== null
                    && $locked->payment_method !== PayoutMethod::AccountCredit->value) {
                    return false;
                }

                if ($locked->status !== 'approved' && $locked->status !== 'paid') {
                    return false;
                }

                $profile = $locked->ambassadorProfile()->lockForUpdate()->first();
                if (! $profile) {
                    return false;
                }

                $bonus = $this->ledger->creditRewardBonus(
                    profile: $profile,
                    amountMinor: $bonusMinor,
                    currency: $locked->currency,
                    rewardId: $locked->id,
                    actor: $actor,
                    note: $note,
                );
            }

            if (! $this->bonusCreditMatchesReward($bonus, $locked)) {
                return false;
            }
        }

        if ($locked->status === 'paid'
            && $locked->payment_method === PayoutMethod::AccountCredit->value
            && (int) $locked->account_credit_transaction_id === (int) $base->id) {
            return true;
        }

        // Incomplete linkage on an already-paid AC reward — repair metadata only.
        if ($locked->status === 'paid') {
            if ($locked->payment_method !== null
                && $locked->payment_method !== PayoutMethod::AccountCredit->value) {
                // Already paid by another method — never silently rewrite to AC.
                return false;
            }

            $locked->update([
                'payment_method' => PayoutMethod::AccountCredit->value,
                'payment_reference' => $base->idempotency_key,
                'account_credit_transaction_id' => $base->id,
                'paid_at' => $locked->paid_at ?? now(),
                'paid_by_user_id' => $locked->paid_by_user_id ?? $actor?->getKey(),
                'note' => $note !== null && $note !== '' ? $note : $locked->note,
            ]);

            return $this->isConsistentlyPaidWithCredit($locked->fresh(), $base);
        }

        if ($locked->status !== 'approved') {
            return false;
        }

        return $this->markRewardPaidWithCredit($locked, $base, $actor, $note, dispatchEvent: true);
    }

    private function markRewardPaidWithCredit(
        Reward $locked,
        AccountCreditTransaction $tx,
        ?User $actor,
        ?string $note,
        bool $dispatchEvent,
    ): bool {
        $updated = Reward::query()
            ->whereKey($locked->id)
            ->where('status', 'approved')
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by_user_id' => $actor?->getKey(),
                'payment_method' => PayoutMethod::AccountCredit->value,
                'payment_reference' => $tx->idempotency_key,
                'account_credit_transaction_id' => $tx->id,
                'note' => $note !== null && $note !== '' ? $note : $locked->note,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $fresh = $locked->fresh();
            if ($fresh && $this->isConsistentlyPaidWithCredit($fresh, $tx)) {
                return true;
            }

            return false;
        }

        $fresh = $locked->fresh();
        if (! $fresh || ! $this->isConsistentlyPaidWithCredit($fresh, $tx)) {
            return false;
        }

        AuditLogger::record(
            action: 'reward.paid',
            subject: $fresh,
            actor: $actor,
            after: [
                'payment_method' => PayoutMethod::AccountCredit->value,
                'account_credit_transaction_id' => $tx->id,
                'amount_minor' => $locked->amount_minor,
                'account_credit_bonus_minor_snapshot' => $locked->accountCreditBonusMinor(),
            ],
        );

        if ($dispatchEvent) {
            RewardPaid::dispatch($fresh);
        }

        return true;
    }

    private function assertPreferredAccountCredit(Reward $reward): void
    {
        $method = $reward->fulfilmentPayoutMethod();

        if ($method !== PayoutMethod::AccountCredit) {
            throw new InvalidArgumentException(
                'Account Credit fulfilment requires this reward to have been claimed for Account Credit.'
            );
        }
    }

    private function baseCreditMatchesReward(AccountCreditTransaction $tx, Reward $reward): bool
    {
        return (int) $tx->reward_id === (int) $reward->id
            && (int) $tx->ambassador_profile_id === (int) $reward->ambassador_profile_id
            && $tx->source === AccountCreditTransaction::SOURCE_REWARD_FULFILMENT
            && $tx->direction === AccountCreditTransaction::DIRECTION_CREDIT
            && (int) $tx->amount_minor === (int) $reward->amount_minor
            && strtolower((string) $tx->currency) === strtolower((string) $reward->currency);
    }

    private function bonusCreditMatchesReward(AccountCreditTransaction $tx, Reward $reward): bool
    {
        return (int) $tx->reward_id === (int) $reward->id
            && (int) $tx->ambassador_profile_id === (int) $reward->ambassador_profile_id
            && $tx->source === AccountCreditTransaction::SOURCE_REWARD_BONUS
            && $tx->direction === AccountCreditTransaction::DIRECTION_CREDIT
            && (int) $tx->amount_minor === $reward->accountCreditBonusMinor()
            && strtolower((string) $tx->currency) === strtolower((string) $reward->currency);
    }

    private function isConsistentlyPaidWithCredit(Reward $reward, AccountCreditTransaction $tx): bool
    {
        return $reward->status === 'paid'
            && $reward->payment_method === PayoutMethod::AccountCredit->value
            && (int) $reward->account_credit_transaction_id === (int) $tx->id
            && $reward->paid_at !== null;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
