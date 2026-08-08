<?php

namespace App\Domain\Referrals;

use App\Domain\Referrals\Events\ReferralConversionApproved;
use App\Domain\Rewards\RewardFundingIntegrityService;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\User;
use App\Notifications\AmbassadorConversionApprovedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages the lifecycle of a `referral_conversions` row.
 *
 * Created (pending) → Approved (after refund window) or Reversed (refund/chargeback).
 */
class ConversionService
{
    public function __construct(
        private readonly RewardFundingIntegrityService $fundingIntegrity,
    ) {}

    public function createPendingFromPurchase(Purchase $purchase): ?ReferralConversion
    {
        if (! $purchase->ambassador_profile_id_snapshot || ! $purchase->referral_code_snapshot) {
            return null;
        }
        if ($purchase->status !== 'paid') {
            return null;
        }

        $existing = ReferralConversion::where('purchase_id', $purchase->id)->first();
        if ($existing) {
            return $existing;
        }

        $windowDays = (int) config('referrals.conversion.refund_window_days', 14);

        $conversion = ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $purchase->ambassador_profile_id_snapshot,
            'referral_code_snapshot' => $purchase->referral_code_snapshot,
            'status' => 'pending',
            'amount_minor' => $purchase->amount_minor,
            'currency' => $purchase->currency,
            'pending_until' => now()->addDays($windowDays),
        ]);

        AuditLogger::record(
            action: 'conversion.created',
            subject: $conversion,
            after: ['status' => 'pending', 'pending_until' => $conversion->pending_until?->toIso8601String()],
        );

        return $conversion;
    }

    public function approve(ReferralConversion $conversion, ?User $actor = null, bool $auto = false): bool
    {
        return (bool) DB::transaction(function () use ($conversion, $actor, $auto) {
            /** @var ReferralConversion|null $locked */
            $locked = ReferralConversion::query()->whereKey($conversion->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            // Manual and auto approval must refuse ineligible / unpaid purchases.
            if (! $this->purchaseStillEligibleForApproval($locked)) {
                return false;
            }

            $updated = ReferralConversion::query()
                ->whereKey($locked->id)
                ->where('status', 'pending')
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
            AuditLogger::record(
                action: $auto ? 'conversion.approved_auto' : 'conversion.approved',
                subject: $fresh,
                actor: $actor,
            );

            $ambassador = $fresh->ambassadorProfile()->with('user')->first();
            if ($ambassador?->user) {
                $ambassador->user->notify(new AmbassadorConversionApprovedNotification($fresh));
            }
            ReferralConversionApproved::dispatch($fresh, $auto);

            return true;
        }, attempts: 3);
    }

    public function reverse(ReferralConversion $conversion, string $reason, ?User $actor = null): bool
    {
        return (bool) DB::transaction(function () use ($conversion, $reason, $actor) {
            /** @var ReferralConversion|null $locked */
            $locked = ReferralConversion::query()->whereKey($conversion->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'reversed') {
                return false;
            }

            $updated = ReferralConversion::query()
                ->whereKey($locked->id)
                ->where('status', '!=', 'reversed')
                ->update([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                    'reversed_by_user_id' => $actor?->getKey(),
                    'reversed_reason' => $reason,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $fresh = $locked->fresh();
            AuditLogger::record(
                action: 'conversion.reversed',
                subject: $fresh,
                after: ['reason' => $reason],
                actor: $actor,
            );

            $this->fundingIntegrity->handleConversionReversed($fresh, $reason);

            return true;
        }, attempts: 3);
    }

    public function reverseByPurchase(Purchase $purchase, string $reason): void
    {
        $conversion = ReferralConversion::where('purchase_id', $purchase->id)->first();
        if ($conversion) {
            $this->reverse($conversion, $reason);
        }
    }

    /**
     * Eligibility criteria for automatic approval:
     *   - conversion.status = 'pending'
     *   - linked purchase is 'paid' AND fulfilment_status = 'completed'
     *   - purchase is NOT refunded / chargeback
     *   - purchase.paid_at <= now() - approval_window_days
     *   - ambassador profile is not flagged AND linked user is_active
     *
     * @return Builder<ReferralConversion>
     */
    public function eligibleForApprovalQuery(?Carbon $now = null): Builder
    {
        $now ??= now();
        $days = (int) config('referrals.conversion.approval_window_days', 14);
        $cutoff = $now->copy()->subDays($days);

        return ReferralConversion::query()
            ->where('status', 'pending')
            ->whereHas('purchase', function (Builder $q) use ($cutoff): void {
                $q->where('status', 'paid')
                    ->where('fulfilment_status', 'completed')
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '<=', $cutoff)
                    ->whereNotIn('status', ['refunded', 'chargeback']);
            })
            ->whereHas('ambassadorProfile', function (Builder $q): void {
                $q->where('flagged_for_review', false)
                    ->whereHas('user', fn (Builder $u) => $u->where('is_active', true));
            });
    }

    /**
     * Run the automatic approval sweep. Safe to run concurrently — each
     * candidate row is locked for update inside its own transaction and
     * the eligibility check is re-evaluated under the lock.
     *
     * @return array{scanned:int, approved:int, skipped:int}
     */
    public function runApprovalSweep(?User $actor = null, ?int $batchSize = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $batchSize ??= (int) config('referrals.conversion.approval_batch_size', 100);
        $scanned = 0;
        $approved = 0;
        $skipped = 0;

        $candidateIds = $this->eligibleForApprovalQuery($now)
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        foreach ($candidateIds as $id) {
            $scanned++;
            DB::transaction(function () use ($id, $actor, $now, &$approved, &$skipped): void {
                /** @var ReferralConversion|null $c */
                $c = ReferralConversion::whereKey($id)->lockForUpdate()->first();
                if (! $c || $c->status !== 'pending') {
                    $skipped++;

                    return;
                }
                // Re-check eligibility under lock (state may have shifted).
                $eligible = $this->eligibleForApprovalQuery($now)
                    ->whereKey($id)
                    ->exists();
                if (! $eligible) {
                    $skipped++;

                    return;
                }
                if ($this->approve($c, $actor, auto: true)) {
                    $approved++;
                } else {
                    $skipped++;
                }
            });
        }

        AuditLogger::record(
            action: 'conversion.sweep.completed',
            subject: null,
            after: ['scanned' => $scanned, 'approved' => $approved, 'skipped' => $skipped],
            actor: $actor,
        );

        return ['scanned' => $scanned, 'approved' => $approved, 'skipped' => $skipped];
    }

    private function purchaseStillEligibleForApproval(ReferralConversion $conversion): bool
    {
        $purchase = $conversion->purchase()->first();
        if (! $purchase) {
            return false;
        }

        return $purchase->status === 'paid';
    }
}
