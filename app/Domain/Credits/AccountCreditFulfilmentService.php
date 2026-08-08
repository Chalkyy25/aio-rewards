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
use RuntimeException;

/**
 * Atomically fulfil an approved reward as Account Credit.
 *
 * Invariants:
 *  - reward becomes paid only if the ledger credit commits
 *  - the same reward never credits twice (idempotency_key + unique reward+source)
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
     * @throws RuntimeException
     */
    public function apply(Reward $reward, ?User $actor = null, ?string $note = null): bool
    {
        try {
            return (bool) DB::transaction(function () use ($reward, $actor, $note) {
                /** @var Reward|null $locked */
                $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
                if (! $locked) {
                    return false;
                }

                // Idempotent: already fulfilled as Account Credit.
                if ($locked->status === 'paid' && $locked->account_credit_transaction_id) {
                    return true;
                }

                $existingCredit = AccountCreditTransaction::query()
                    ->where('reward_id', $locked->id)
                    ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)
                    ->first();
                if ($existingCredit) {
                    if ($locked->status !== 'paid') {
                        Reward::query()->whereKey($locked->id)->where('status', 'approved')->update([
                            'status' => 'paid',
                            'paid_at' => $locked->paid_at ?? now(),
                            'paid_by_user_id' => $locked->paid_by_user_id ?? $actor?->getKey(),
                            'payment_method' => PayoutMethod::AccountCredit->value,
                            'payment_reference' => $existingCredit->idempotency_key,
                            'account_credit_transaction_id' => $existingCredit->id,
                            'note' => $note !== null && $note !== '' ? $note : $locked->note,
                            'updated_at' => now(),
                        ]);
                    }

                    return true;
                }

                if ($locked->status !== 'approved') {
                    return false;
                }

                $this->funding->assertFundable($locked);

                $profile = $locked->ambassadorProfile()->lockForUpdate()->first();
                if (! $profile) {
                    throw new RuntimeException('Ambassador profile missing for Account Credit fulfilment.');
                }

                $tx = $this->ledger->creditRewardFulfilment(
                    profile: $profile,
                    amountMinor: $locked->amount_minor,
                    currency: $locked->currency,
                    rewardId: $locked->id,
                    actor: $actor,
                    note: $note,
                );

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
                    throw new RuntimeException('Failed to mark reward paid after Account Credit post.');
                }

                $fresh = $locked->fresh();
                AuditLogger::record(
                    action: 'reward.paid',
                    subject: $fresh,
                    actor: $actor,
                    after: [
                        'payment_method' => PayoutMethod::AccountCredit->value,
                        'account_credit_transaction_id' => $tx->id,
                        'amount_minor' => $locked->amount_minor,
                    ],
                );
                RewardPaid::dispatch($fresh);

                return true;
            }, attempts: 3);
        } catch (QueryException $e) {
            // Unique reward+source race: treat as idempotent success if credit exists.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains(strtolower($e->getMessage()), 'unique')) {
                $existing = AccountCreditTransaction::query()
                    ->where('reward_id', $reward->id)
                    ->where('source', AccountCreditTransaction::SOURCE_REWARD_FULFILMENT)
                    ->first();
                if ($existing) {
                    return true;
                }
            }
            throw $e;
        }
    }
}
