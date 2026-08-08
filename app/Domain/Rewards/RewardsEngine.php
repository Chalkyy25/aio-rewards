<?php

namespace App\Domain\Rewards;

use App\Domain\Rewards\Events\RewardApproved;
use App\Domain\Rewards\Events\RewardCreated;
use App\Domain\Rewards\Events\RewardPaid;
use App\Domain\Rewards\Events\RewardReversed;
use App\Models\AmbassadorProfile;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Central owner of the Reward lifecycle. Evaluates every active
 * RewardRule against the approving ambassador's totals and creates
 * Reward rows via the DB unique index (ambassador × rule × milestone)
 * to eliminate duplicates even under concurrent approvals.
 */
class RewardsEngine
{
    /**
     * Evaluate all active rules for the ambassador whose conversion just
     * got approved. Called by the ReferralConversionApproved listener.
     *
     * @return array<int, Reward> freshly-created rewards
     */
    public function onConversionApproved(ReferralConversion $conversion): array
    {
        $profile = $conversion->ambassadorProfile()->first();
        if (! $profile || ! $profile->user?->is_active) {
            return [];
        }
        if ($profile->flagged_for_review) {
            return [];
        }

        $approvedCount = ReferralConversion::query()
            ->where('ambassador_profile_id', $profile->id)
            ->where('status', 'approved')
            ->count();

        $created = [];
        foreach (RewardRule::query()->where('is_active', true)->get() as $rule) {
            $reward = $this->evaluateRule($rule, $profile, $conversion, $approvedCount);
            if ($reward) {
                $created[] = $reward;
            }
        }

        return $created;
    }

    private function evaluateRule(
        RewardRule $rule,
        AmbassadorProfile $profile,
        ReferralConversion $conversion,
        int $approvedCount,
    ): ?Reward {
        if ($rule->kind !== 'every_n_cash') {
            return null; // reserved for later kinds
        }
        if ($rule->trigger_count < 1 || $rule->amount_minor <= 0) {
            return null;
        }
        // Milestone bucket hit only when the counter lands exactly on a multiple.
        if ($approvedCount === 0 || $approvedCount % $rule->trigger_count !== 0) {
            return null;
        }
        $milestone = intdiv($approvedCount, $rule->trigger_count);

        // Unique index guarantees exactly-once creation even if two approval
        // events race against each other.
        return DB::transaction(function () use ($rule, $profile, $conversion, $milestone) {
            $existing = Reward::where('ambassador_profile_id', $profile->id)
                ->where('reward_rule_id', $rule->id)
                ->where('milestone_index', $milestone)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return null;
            }

            $reward = Reward::create([
                'ambassador_profile_id' => $profile->id,
                'reward_rule_id' => $rule->id,
                'trigger_conversion_id' => $conversion->id,
                'milestone_index' => $milestone,
                'amount_minor' => $rule->amount_minor,
                'currency' => $rule->currency,
                'status' => 'pending_approval',
            ]);

            AuditLogger::record('reward.created', $reward, after: [
                'rule' => $rule->name,
                'milestone' => $milestone,
                'amount_minor' => $reward->amount_minor,
            ]);
            RewardCreated::dispatch($reward);

            return $reward;
        });
    }

    public function approve(Reward $reward, ?User $actor = null): bool
    {
        if ($reward->status !== 'pending_approval') {
            return false;
        }
        $reward->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $actor?->getKey(),
        ]);
        AuditLogger::record('reward.approved', $reward, actor: $actor);
        RewardApproved::dispatch($reward->fresh());

        return true;
    }

    public function reject(Reward $reward, ?User $actor = null, ?string $note = null): bool
    {
        if (! in_array($reward->status, ['pending_approval', 'approved'], true)) {
            return false;
        }
        $reward->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by_user_id' => $actor?->getKey(),
            'note' => $note ?: $reward->note,
        ]);
        AuditLogger::record('reward.rejected', $reward, actor: $actor, after: ['note' => $note]);

        return true;
    }

    /**
     * Record that an admin has manually paid an approved reward.
     *
     * This never initiates a bank transfer — it only stores payment metadata
     * after the operator confirms they sent the money outside AIO Rewards.
     */
    public function markPaid(
        Reward $reward,
        ?User $actor = null,
        ?string $note = null,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
    ): bool {
        if ($reward->status !== 'approved') {
            return false;
        }

        $method = $paymentMethod
            ?: $reward->ambassadorProfile?->payoutProfile?->preferred_method?->value
            ?: 'bank_transfer';

        $reward->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by_user_id' => $actor?->getKey(),
            'payment_method' => $method,
            'payment_reference' => $paymentReference !== null && $paymentReference !== ''
                ? $paymentReference
                : $reward->payment_reference,
            'note' => $note !== null && $note !== '' ? $note : $reward->note,
        ]);
        AuditLogger::record(
            'reward.paid',
            $reward,
            actor: $actor,
            after: [
                'payment_method' => $method,
                'has_payment_reference' => filled($paymentReference),
            ],
        );
        RewardPaid::dispatch($reward->fresh());

        return true;
    }

    public function reverse(Reward $reward, ?User $actor = null, ?string $note = null): bool
    {
        if ($reward->status === 'reversed') {
            return false;
        }
        $reward->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by_user_id' => $actor?->getKey(),
            'note' => $note ?: $reward->note,
        ]);
        AuditLogger::record('reward.reversed', $reward, actor: $actor, after: ['note' => $note]);
        RewardReversed::dispatch($reward->fresh());

        return true;
    }
}
