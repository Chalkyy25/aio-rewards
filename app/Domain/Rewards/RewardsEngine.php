<?php

namespace App\Domain\Rewards;

use App\Domain\Rewards\Events\RewardApproved;
use App\Domain\Rewards\Events\RewardPaid;
use App\Domain\Rewards\Events\RewardReversed;
use App\Enums\PayoutMethod;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Central owner of the Reward lifecycle transitions.
 *
 * New rewards are created exclusively via MilestoneProgressionService.
 * The legacy every_n_cash auto-mint path is disabled for launch.
 */
class RewardsEngine
{
    public function __construct(
        private readonly RewardFundingIntegrityService $funding,
    ) {}

    /**
     * Legacy every_n_cash evaluation — DISABLED for launch.
     *
     * Milestone progression + ReferralAllocation is the single source of
     * truth for new rewards. Historical RewardRule / Reward rows are retained.
     *
     * @return array<int, Reward>
     */
    public function onConversionApproved(ReferralConversion $conversion): array
    {
        return [];
    }

    /**
     * @throws RewardFundingIntegrityException
     */
    public function approve(Reward $reward, ?User $actor = null): bool
    {
        return (bool) DB::transaction(function () use ($reward, $actor) {
            /** @var Reward|null $locked */
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending_approval') {
                return false;
            }

            $this->funding->assertFundable($locked);

            $updated = Reward::query()
                ->whereKey($locked->id)
                ->where('status', 'pending_approval')
                ->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by_user_id' => $actor?->getKey(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $fresh = $locked->fresh();
            AuditLogger::record('reward.approved', $fresh, actor: $actor);
            RewardApproved::dispatch($fresh);

            return true;
        }, attempts: 3);
    }

    public function reject(Reward $reward, ?User $actor = null, ?string $note = null): bool
    {
        return (bool) DB::transaction(function () use ($reward, $actor, $note) {
            /** @var Reward|null $locked */
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, ['pending_approval', 'approved'], true)) {
                return false;
            }

            $updated = Reward::query()
                ->whereKey($locked->id)
                ->whereIn('status', ['pending_approval', 'approved'])
                ->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                    'rejected_by_user_id' => $actor?->getKey(),
                    'note' => $note ?: $locked->note,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            AuditLogger::record('reward.rejected', $locked->fresh(), actor: $actor, after: ['note' => $note]);

            return true;
        }, attempts: 3);
    }

    /**
     * Record that an admin has manually paid an approved reward via Bank Transfer
     * (or other external method). Does NOT move money and must NOT be used for
     * Account Credit — use AccountCreditFulfilmentService instead.
     *
     * @throws RewardFundingIntegrityException
     */
    public function markPaid(
        Reward $reward,
        ?User $actor = null,
        ?string $note = null,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
    ): bool {
        return (bool) DB::transaction(function () use ($reward, $actor, $note, $paymentMethod, $paymentReference) {
            /** @var Reward|null $locked */
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'approved') {
                return false;
            }

            $method = $paymentMethod
                ?: $locked->ambassadorProfile?->payoutProfile?->preferred_method?->value
                ?: PayoutMethod::BankTransfer->value;

            if ($method === PayoutMethod::AccountCredit->value) {
                // Account Credit must go through the ledger fulfilment path.
                return false;
            }

            $this->funding->assertFundable($locked);

            $updated = Reward::query()
                ->whereKey($locked->id)
                ->where('status', 'approved')
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'paid_by_user_id' => $actor?->getKey(),
                    'payment_method' => $method,
                    'payment_reference' => $paymentReference !== null && $paymentReference !== ''
                        ? $paymentReference
                        : $locked->payment_reference,
                    'note' => $note !== null && $note !== '' ? $note : $locked->note,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $fresh = $locked->fresh();
            AuditLogger::record(
                'reward.paid',
                $fresh,
                actor: $actor,
                after: [
                    'payment_method' => $method,
                    'has_payment_reference' => filled($paymentReference),
                ],
            );
            RewardPaid::dispatch($fresh);

            return true;
        }, attempts: 3);
    }

    /**
     * Reverse a historically paid reward for accounting. Unpaid rewards must
     * use reject-and-release / reject-and-consume (milestone) or reject().
     *
     * Does not destroy payment metadata. Does not release allocations
     * (paid reverse keeps referrals consumed per project invariant).
     */
    public function reverse(Reward $reward, ?User $actor = null, ?string $note = null): bool
    {
        return (bool) DB::transaction(function () use ($reward, $actor, $note) {
            /** @var Reward|null $locked */
            $locked = Reward::query()->whereKey($reward->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'paid') {
                return false;
            }

            $updated = Reward::query()
                ->whereKey($locked->id)
                ->where('status', 'paid')
                ->update([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                    'reversed_by_user_id' => $actor?->getKey(),
                    'note' => $note ?: $locked->note,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $fresh = $locked->fresh();
            AuditLogger::record('reward.reversed', $fresh, actor: $actor, after: ['note' => $note]);
            RewardReversed::dispatch($fresh);

            return true;
        }, attempts: 3);
    }
}
