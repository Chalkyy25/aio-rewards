<?php

namespace App\Domain\Rewards;

use App\Domain\Rewards\Events\RewardCreated;
use App\Models\AmbassadorProfile;
use App\Models\ReferralAllocation;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardMilestoneTier;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Core progression engine for the data-driven Reward Milestone ladder.
 *
 *  Financial invariants enforced here:
 *  1. The same qualifying ReferralConversion can never fund two
 *     overlapping payouts — enforced by `referral_allocations` with a
 *     `(referral_conversion_id, active_marker)` unique index.
 *  2. Available progress ≠ a submitted claim. Rewards only exist once
 *     the member presses Cash Out, at which point `claim()` locks and
 *     allocates the exact qualifying conversions.
 *  3. Claims are idempotent per (member, tier, cycle) via a unique
 *     idempotency key on `rewards.idempotency_key` + unique index on
 *     `(ambassador_profile_id, milestone_tier_id, cycle_number)`.
 */
class MilestoneProgressionService
{
    /**
     * Compute the member's live progression snapshot.
     *
     * Eligible = approved conversion, owned by member, and not currently
     * allocated to any active Reward claim.
     */
    public function progressFor(AmbassadorProfile $profile): MilestoneProgress
    {
        $tiers = RewardMilestoneTier::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('threshold')
            ->get()
            ->values();

        $eligibleCount = $this->eligibleConversionsQuery($profile->id)->count();
        $cycleNumber = $this->currentCycleNumber($profile->id);

        $availableTier = null;
        $nextTier = null;
        foreach ($tiers as $tier) {
            if (! $tier->is_claimable) {
                continue;
            }
            if ($eligibleCount >= $tier->threshold) {
                // Highest reached wins.
                $availableTier = $tier;
            } else {
                $nextTier = $tier;
                break;
            }
        }

        // The bonus being built = bonus_amount_minor of the next claimable tier we haven't hit yet.
        $bonusBeingBuilt = $nextTier?->bonus_amount_minor ?? 0;
        $referralsRemaining = $nextTier
            ? max(0, $nextTier->threshold - $eligibleCount)
            : 0;

        $ladder = $tiers->map(function (RewardMilestoneTier $t) use ($eligibleCount, $availableTier) {
            $reached = $eligibleCount >= $t->threshold;

            return [
                'tier' => $t,
                'reached' => $reached,
                'is_current_claim' => $availableTier && $availableTier->id === $t->id,
                'is_next' => (! $reached),
            ];
        })->all();

        return new MilestoneProgress(
            cycleNumber: $cycleNumber,
            eligibleCount: $eligibleCount,
            availableTier: $availableTier,
            nextTier: $nextTier,
            availableAmountMinor: $availableTier?->total_reward_amount_minor ?? 0,
            referralsRemaining: $referralsRemaining,
            bonusBeingBuiltMinor: $bonusBeingBuilt,
            tiers: $tiers->all(),
            ladder: $ladder,
        );
    }

    /**
     * Claim a specific tier. Idempotent and concurrency-safe.
     *
     * @throws MilestoneClaimUnavailableException when the tier is no longer available.
     */
    public function claim(
        AmbassadorProfile $profile,
        RewardMilestoneTier $tier,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): Reward {
        if ($profile->flagged_for_review || ! $profile->user?->is_active) {
            throw new MilestoneClaimUnavailableException('Account cannot claim rewards right now.');
        }
        if (! $tier->is_active || ! $tier->is_claimable) {
            throw new MilestoneClaimUnavailableException('This reward is no longer claimable.');
        }

        return DB::transaction(function () use ($profile, $tier, $actor, $idempotencyKey) {
            // Lock the profile row to serialize concurrent claims by the same member.
            AmbassadorProfile::query()->whereKey($profile->id)->lockForUpdate()->first();

            $cycleNumber = $this->currentCycleNumber($profile->id);
            $key = $idempotencyKey
                ?: sprintf('mc:%d:%d:%d', $profile->id, $cycleNumber, $tier->id);

            // Idempotency: if a reward already exists for this key, return it.
            $existing = Reward::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            // Re-query eligible conversions with lock, then verify the tier is still valid.
            $eligible = $this->eligibleConversionsQuery($profile->id)
                ->orderBy('approved_at')
                ->orderBy('id')
                ->limit($tier->threshold)
                ->lockForUpdate()
                ->get();

            if ($eligible->count() < $tier->threshold) {
                throw new MilestoneClaimUnavailableException(
                    'Not enough approved referrals are available for this reward.'
                );
            }

            // Verify no HIGHER active+claimable tier is currently reached — if it is,
            // this specific tier is superseded and cannot be claimed.
            $totalEligible = $this->eligibleConversionsQuery($profile->id)->count();
            $supersededBy = RewardMilestoneTier::query()
                ->where('is_active', true)
                ->where('is_claimable', true)
                ->where('threshold', '>', $tier->threshold)
                ->where('threshold', '<=', $totalEligible)
                ->orderBy('threshold', 'desc')
                ->first();
            if ($supersededBy) {
                throw new MilestoneClaimUnavailableException(
                    'A higher reward tier is now available — please refresh.'
                );
            }

            try {
                $reward = Reward::create([
                    'ambassador_profile_id' => $profile->id,
                    'reward_rule_id' => null,
                    'trigger_conversion_id' => $eligible->last()->id,
                    'milestone_tier_id' => $tier->id,
                    'milestone_index' => $tier->threshold, // for legacy compat / reporting
                    'cycle_number' => $cycleNumber,
                    'origin' => 'milestone_claim',
                    'tier_snapshot' => $tier->snapshot(),
                    'idempotency_key' => $key,
                    'amount_minor' => $tier->total_reward_amount_minor,
                    'currency' => $tier->currency,
                    'status' => 'pending_approval',
                ]);
            } catch (QueryException $e) {
                // Unique-constraint race: someone claimed at the same moment.
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    $existing = Reward::where('idempotency_key', $key)->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                throw $e;
            }

            // Allocate exactly $tier->threshold conversions to this reward.
            foreach ($eligible as $conversion) {
                ReferralAllocation::create([
                    'referral_conversion_id' => $conversion->id,
                    'ambassador_profile_id' => $profile->id,
                    'cycle_number' => $cycleNumber,
                    'reward_id' => $reward->id,
                    'active_marker' => 1,
                    'allocated_at' => now(),
                ]);
            }

            AuditLogger::record('reward.milestone_claimed', $reward, actor: $actor, after: [
                'tier_id' => $tier->id,
                'threshold' => $tier->threshold,
                'amount_minor' => $tier->total_reward_amount_minor,
                'cycle_number' => $cycleNumber,
            ]);
            RewardCreated::dispatch($reward);

            return $reward;
        }, attempts: 3);
    }

    /**
     * Reject and release: allocations are freed so the underlying
     * referrals become eligible again.
     */
    public function rejectAndRelease(Reward $reward, User $actor, string $note): bool
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
                    'rejected_by_user_id' => $actor->getKey(),
                    'reject_disposition' => 'release',
                    'note' => $note,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            ReferralAllocation::query()
                ->where('reward_id', $locked->id)
                ->whereNotNull('active_marker')
                ->update([
                    'active_marker' => null,
                    'released_at' => now(),
                    'release_reason' => 'reject_and_release',
                    'updated_at' => now(),
                ]);

            AuditLogger::record('reward.rejected_released', $locked->fresh(), actor: $actor, after: ['note' => $note]);

            return true;
        }, attempts: 3);
    }

    /**
     * Reject and consume: the reward is rejected but allocations stay
     * active — the cycle is closed and referrals cannot fund another claim.
     */
    public function rejectAndConsume(Reward $reward, User $actor, string $note): bool
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
                    'rejected_by_user_id' => $actor->getKey(),
                    'reject_disposition' => 'consume',
                    'note' => $note,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            AuditLogger::record('reward.rejected_consumed', $locked->fresh(), actor: $actor, after: ['note' => $note]);

            return true;
        }, attempts: 3);
    }

    /**
     * Query builder for referrals that are currently eligible to fund a claim:
     * approved status + not already tied to an active allocation.
     */
    public function eligibleConversionsQuery(int $profileId): Builder
    {
        return ReferralConversion::query()
            ->where('ambassador_profile_id', $profileId)
            ->where('status', 'approved')
            ->whereNotIn('id', function ($sub) {
                $sub->select('referral_conversion_id')
                    ->from('referral_allocations')
                    ->whereNotNull('active_marker');
            });
    }

    /**
     * The current progression cycle number. A cycle closes each time a
     * milestone reward is created (idempotently), so the next cycle is
     * one more than the count of prior milestone_claim rewards owned by
     * this member.
     */
    public function currentCycleNumber(int $profileId): int
    {
        $priorCycles = Reward::query()
            ->where('ambassador_profile_id', $profileId)
            ->where('origin', 'milestone_claim')
            ->count();

        return $priorCycles + 1;
    }
}
