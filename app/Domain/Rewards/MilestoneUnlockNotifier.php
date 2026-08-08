<?php

namespace App\Domain\Rewards;

use App\Domain\Rewards\Events\RewardMilestoneUnlocked;
use App\Domain\Settings\SettingsRepository;
use App\Models\AmbassadorProfile;
use App\Models\MilestoneUnlockNotification;
use App\Models\User;
use App\Notifications\MilestoneUnlockedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detects genuine not-claimable → newly-claimable transitions using
 * MilestoneProgressionService as the sole eligibility source of truth,
 * then queues a single idempotent unlock notification per
 * (member, cycle, tier).
 */
class MilestoneUnlockNotifier
{
    public function __construct(
        private readonly MilestoneProgressionService $progression,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Evaluate the member's live progression after a qualifying approval
     * and notify when a claimable milestone has become newly available.
     */
    public function evaluate(AmbassadorProfile $profile): ?MilestoneUnlockNotification
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $profile->loadMissing('user');
        $user = $profile->user;
        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }
        if ($profile->flagged_for_review) {
            return null;
        }

        $progress = $this->progression->progressFor($profile);
        $tier = $progress->availableTier;
        if ($tier === null) {
            return null;
        }

        // Progression already filters to active + visible + claimable tiers.
        // Defend in depth if a tier row was mutated mid-request.
        if (! $tier->is_active || ! $tier->is_visible || ! $tier->is_claimable) {
            return null;
        }

        $key = MilestoneUnlockNotification::buildKey(
            $profile->id,
            $progress->cycleNumber,
            $tier->id,
        );

        $snapshot = new MilestoneUnlockSnapshot(
            ambassadorProfileId: $profile->id,
            userId: $user->id,
            cycleNumber: $progress->cycleNumber,
            tierId: $tier->id,
            threshold: $tier->threshold,
            totalRewardAmountMinor: $tier->total_reward_amount_minor,
            bonusAmountMinor: $tier->bonus_amount_minor,
            currency: $tier->currency,
            title: $tier->title,
            eligibleCount: $progress->eligibleCount,
            memberDisplayName: (string) $user->name,
            nextThreshold: $progress->nextTier?->threshold,
            nextTotalRewardAmountMinor: $progress->nextTier?->total_reward_amount_minor,
            nextBonusAmountMinor: $progress->nextTier?->bonus_amount_minor,
            nextTitle: $progress->nextTier?->title,
            idempotencyKey: $key,
        );

        $record = $this->claimOrResume($profile, $user, $progress->cycleNumber, $tier->id, $key, $snapshot);
        if ($record === null) {
            return null;
        }

        RewardMilestoneUnlocked::dispatch($snapshot);

        try {
            $user->notify(new MilestoneUnlockedNotification($snapshot));
            $record->forceFill([
                'status' => MilestoneUnlockNotification::STATUS_QUEUED,
                'queued_at' => now(),
                'failure_class' => null,
                'failed_at' => null,
            ])->save();
        } catch (\Throwable $e) {
            $this->markFailed($key, $e::class);
            Log::error('milestone.unlock_notification.dispatch_failed', [
                'idempotency_key' => $key,
                'ambassador_profile_id' => $profile->id,
                'exception' => $e::class,
            ]);
            AuditLogger::record('notification.milestone_unlock.failed', $record, after: [
                'idempotency_key' => $key,
                'exception' => $e::class,
            ]);
            throw $e;
        }

        AuditLogger::record('notification.milestone_unlock.queued', $record, after: [
            'idempotency_key' => $key,
            'tier_id' => $tier->id,
            'cycle_number' => $progress->cycleNumber,
            'threshold' => $tier->threshold,
        ]);

        return $record->fresh();
    }

    public function markSent(string $idempotencyKey): void
    {
        MilestoneUnlockNotification::query()
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('status', [
                MilestoneUnlockNotification::STATUS_PENDING,
                MilestoneUnlockNotification::STATUS_QUEUED,
                MilestoneUnlockNotification::STATUS_FAILED,
            ])
            ->update([
                'status' => MilestoneUnlockNotification::STATUS_SENT,
                'sent_at' => now(),
                'failure_class' => null,
                'failed_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function markFailed(string $idempotencyKey, string $exceptionClass): void
    {
        // Never persist exception messages — they may contain request/provider context.
        $safeClass = substr(class_basename($exceptionClass) ?: 'Exception', 0, 190);

        MilestoneUnlockNotification::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', '!=', MilestoneUnlockNotification::STATUS_SENT)
            ->update([
                'status' => MilestoneUnlockNotification::STATUS_FAILED,
                'failure_class' => $safeClass,
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function isEnabled(): bool
    {
        return (bool) (int) ($this->settings->value('notifications.milestone_unlock_enabled') ?? '1');
    }

    private function claimOrResume(
        AmbassadorProfile $profile,
        User $user,
        int $cycleNumber,
        int $tierId,
        string $key,
        MilestoneUnlockSnapshot $snapshot,
    ): ?MilestoneUnlockNotification {
        return DB::transaction(function () use ($profile, $user, $cycleNumber, $tierId, $key, $snapshot) {
            $existing = MilestoneUnlockNotification::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Only failed markers may be retried. pending/queued/sent
                // mean another worker owns the logical unlock (or it already completed).
                if ($existing->status !== MilestoneUnlockNotification::STATUS_FAILED) {
                    AuditLogger::record('notification.milestone_unlock.skipped_duplicate', $existing, after: [
                        'idempotency_key' => $key,
                        'status' => $existing->status,
                    ]);

                    return null;
                }

                $existing->forceFill([
                    'status' => MilestoneUnlockNotification::STATUS_PENDING,
                    'tier_snapshot' => $snapshot->toArray(),
                    'failure_class' => null,
                    'failed_at' => null,
                    'queued_at' => null,
                    'sent_at' => null,
                ])->save();

                return $existing;
            }

            try {
                return MilestoneUnlockNotification::create([
                    'ambassador_profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'cycle_number' => $cycleNumber,
                    'milestone_tier_id' => $tierId,
                    'idempotency_key' => $key,
                    'status' => MilestoneUnlockNotification::STATUS_PENDING,
                    'tier_snapshot' => $snapshot->toArray(),
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062 && ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
                AuditLogger::record('notification.milestone_unlock.skipped_duplicate', null, after: [
                    'idempotency_key' => $key,
                    'reason' => 'unique_race',
                ]);

                return null;
            }
        });
    }
}
