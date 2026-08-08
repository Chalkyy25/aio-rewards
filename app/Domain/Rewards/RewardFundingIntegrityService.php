<?php

namespace App\Domain\Rewards;

use App\Domain\Operations\OperationsSpec;
use App\Domain\Operations\OperationsWriter;
use App\Enums\OperationsPriority;
use App\Enums\OperationsType;
use App\Models\ReferralAllocation;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Ensures rewards are only approved / paid / credited when their active
 * funding allocations still reference eligible conversions + purchases.
 *
 * Also reacts to conversion reversal (refund / chargeback).
 */
class RewardFundingIntegrityService
{
    public function __construct(private readonly OperationsWriter $ops) {}

    /**
     * @throws RewardFundingIntegrityException
     */
    public function assertFundable(Reward $reward): void
    {
        if ($reward->funding_compromised_at !== null) {
            throw RewardFundingIntegrityException::invalid('reward previously flagged as funding-compromised');
        }

        if ($reward->origin === 'milestone_claim') {
            $active = ReferralAllocation::query()
                ->with(['conversion.purchase'])
                ->where('reward_id', $reward->id)
                ->whereNotNull('active_marker')
                ->get();

            if ($active->isEmpty()) {
                throw RewardFundingIntegrityException::invalid('milestone reward has no active funding allocations');
            }

            $expected = (int) ($reward->tier_snapshot['threshold'] ?? $reward->milestone_index ?? 0);
            if ($expected > 0 && $active->count() < $expected) {
                throw RewardFundingIntegrityException::invalid(
                    "active allocations ({$active->count()}) below required threshold ({$expected})"
                );
            }

            foreach ($active as $allocation) {
                $this->assertAllocationEligible($allocation);
            }

            return;
        }

        // Legacy / non-milestone: require the trigger conversion (if any) still valid.
        if ($reward->trigger_conversion_id) {
            $conversion = ReferralConversion::query()
                ->with('purchase')
                ->whereKey($reward->trigger_conversion_id)
                ->first();
            if (! $conversion || ! $this->conversionIsEligibleFunding($conversion)) {
                throw RewardFundingIntegrityException::invalid('trigger conversion is no longer eligible funding');
            }
        }
    }

    public function isFundable(Reward $reward): bool
    {
        try {
            $this->assertFundable($reward);

            return true;
        } catch (RewardFundingIntegrityException) {
            return false;
        }
    }

    /**
     * After a conversion is reversed, invalidate unpaid funded rewards and
     * flag already-paid rewards for manual clawback review.
     */
    public function handleConversionReversed(ReferralConversion $conversion, string $reason): void
    {
        DB::transaction(function () use ($conversion, $reason) {
            $allocations = ReferralAllocation::query()
                ->where('referral_conversion_id', $conversion->id)
                ->whereNotNull('active_marker')
                ->lockForUpdate()
                ->get();

            $rewardIds = $allocations->pluck('reward_id')->filter()->unique()->values();
            if ($rewardIds->isEmpty()) {
                return;
            }

            $rewards = Reward::query()
                ->whereIn('id', $rewardIds)
                ->lockForUpdate()
                ->get();

            foreach ($rewards as $reward) {
                if (in_array($reward->status, ['pending_approval', 'approved'], true)) {
                    $this->invalidateUnpaidReward($reward, $conversion, $reason);

                    continue;
                }

                if ($reward->status === 'paid') {
                    $this->flagPaidRewardCompromised($reward, $conversion, $reason);
                }
            }
        });
    }

    private function invalidateUnpaidReward(Reward $reward, ReferralConversion $conversion, string $reason): void
    {
        $note = trim(($reward->note ? $reward->note."\n" : '').
            'Auto-invalidated: funding conversion #'.$conversion->id.' reversed ('.$reason.').');

        $reward->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'reject_disposition' => 'release',
            'note' => $note,
            'funding_compromised_at' => now(),
            'funding_compromise_reason' => $reason,
            'funding_compromise_conversion_id' => $conversion->id,
        ]);

        ReferralAllocation::query()
            ->where('reward_id', $reward->id)
            ->whereNotNull('active_marker')
            ->update([
                'active_marker' => null,
                'released_at' => now(),
                'release_reason' => 'funding_conversion_reversed',
                'updated_at' => now(),
            ]);

        AuditLogger::record('reward.funding_invalidated', $reward, after: [
            'conversion_id' => $conversion->id,
            'reason' => $reason,
            'previous_status_was_unpaid' => true,
        ]);
    }

    private function flagPaidRewardCompromised(Reward $reward, ReferralConversion $conversion, string $reason): void
    {
        // Never erase historical payment / credit.
        $reward->update([
            'funding_compromised_at' => $reward->funding_compromised_at ?? now(),
            'funding_compromise_reason' => $reason,
            'funding_compromise_conversion_id' => $conversion->id,
        ]);

        $method = $reward->payment_method ?? 'unknown';
        $this->ops->upsert(new OperationsSpec(
            type: OperationsType::RewardPaidFundingCompromised,
            dedupeKey: 'rewards.paid_funding_compromised:'.$reward->id.':'.$conversion->id,
            title: 'Paid reward funding compromised by '.strtoupper($reason),
            summary: 'Reward #'.$reward->id.' was already paid ('.$method.') but funding conversion #'
                .$conversion->id.' was reversed ('.$reason.'). Manual review / clawback required. '
                .'Do not erase payment history.',
            priority: OperationsPriority::Critical,
            subject: $reward,
            meta: [
                'reward_id' => $reward->id,
                'conversion_id' => $conversion->id,
                'purchase_id' => $conversion->purchase_id,
                'payment_method' => $method,
                'amount_minor' => $reward->amount_minor,
                'reason' => $reason,
                'account_credit_transaction_id' => $reward->account_credit_transaction_id,
            ],
        ));

        AuditLogger::record('reward.funding_compromised_paid', $reward, after: [
            'conversion_id' => $conversion->id,
            'reason' => $reason,
            'payment_method' => $method,
        ]);
    }

    private function assertAllocationEligible(ReferralAllocation $allocation): void
    {
        $conversion = $allocation->conversion;
        if (! $conversion || ! $this->conversionIsEligibleFunding($conversion)) {
            throw RewardFundingIntegrityException::invalid(
                'allocation #'.$allocation->id.' references ineligible conversion'
            );
        }
    }

    public function conversionIsEligibleFunding(ReferralConversion $conversion): bool
    {
        if ($conversion->status !== 'approved') {
            return false;
        }

        $purchase = $conversion->relationLoaded('purchase')
            ? $conversion->purchase
            : $conversion->purchase()->first();

        if (! $purchase) {
            return false;
        }

        // Purchase must still be a paid, non-refunded / non-chargeback order.
        return $purchase->status === 'paid';
    }
}
