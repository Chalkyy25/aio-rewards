<?php

namespace App\Domain\Referrals;

use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\User;
use App\Support\Audit\AuditLogger;

/**
 * Manages the lifecycle of a `referral_conversions` row.
 *
 * Created (pending) → Approved (after refund window) or Reversed (refund/chargeback).
 */
class ConversionService
{
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

    public function approve(ReferralConversion $conversion, ?User $actor = null): bool
    {
        if ($conversion->status !== 'pending') {
            return false;
        }
        $conversion->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $actor?->getKey(),
        ]);
        AuditLogger::record('conversion.approved', $conversion, actor: $actor);

        return true;
    }

    public function reverse(ReferralConversion $conversion, string $reason, ?User $actor = null): bool
    {
        if ($conversion->status === 'reversed') {
            return false;
        }
        $conversion->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by_user_id' => $actor?->getKey(),
            'reversed_reason' => $reason,
        ]);
        AuditLogger::record(
            action: 'conversion.reversed',
            subject: $conversion,
            after: ['reason' => $reason],
            actor: $actor,
        );

        return true;
    }

    public function reverseByPurchase(Purchase $purchase, string $reason): void
    {
        $conversion = ReferralConversion::where('purchase_id', $purchase->id)->first();
        if ($conversion) {
            $this->reverse($conversion, $reason);
        }
    }
}
