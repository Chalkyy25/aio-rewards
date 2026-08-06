<?php

namespace App\Domain\Notifications;

use App\Models\Purchase;
use App\Notifications\BuyerOrderCompletedNotification;
use App\Notifications\BuyerPaymentReceivedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatches buyer-facing emails and records the send in an idempotent way
 * (a dedicated timestamp column on `purchases` acts as the "already sent"
 * flag; the row is locked to prevent duplicate emails under concurrent
 * webhook + admin action races). All attempts and skips are audited.
 */
class BuyerOrderNotifier
{
    public function sendPaymentReceived(Purchase $purchase): bool
    {
        return $this->dispatchOnce(
            purchase: $purchase,
            column: 'payment_email_sent_at',
            action: 'email.buyer_payment_received',
            factory: fn (Purchase $p) => new BuyerPaymentReceivedNotification($p),
        );
    }

    public function sendOrderCompleted(Purchase $purchase): bool
    {
        return $this->dispatchOnce(
            purchase: $purchase,
            column: 'completed_email_sent_at',
            action: 'email.buyer_order_completed',
            factory: fn (Purchase $p) => new BuyerOrderCompletedNotification($p),
        );
    }

    /**
     * @param callable(Purchase):\Illuminate\Notifications\Notification $factory
     */
    private function dispatchOnce(Purchase $purchase, string $column, string $action, callable $factory): bool
    {
        if (! $purchase->buyer_email) {
            AuditLogger::record($action.'.skipped', $purchase, after: ['reason' => 'no_buyer_email']);

            return false;
        }

        // Row-locked flag flip to defeat concurrent double-sends.
        $sent = DB::transaction(function () use ($purchase, $column) {
            /** @var Purchase|null $locked */
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->first();
            if (! $locked || $locked->{$column}) {
                return false;
            }
            $locked->forceFill([$column => now()])->save();

            return true;
        });

        if (! $sent) {
            AuditLogger::record($action.'.skipped_duplicate', $purchase);

            return false;
        }

        try {
            Notification::route('mail', $purchase->buyer_email)->notify($factory($purchase));
            AuditLogger::record($action.'.sent', $purchase, after: [
                'to' => $purchase->buyer_email,
                'ref' => $purchase->orderReference(),
            ]);

            return true;
        } catch (\Throwable $e) {
            // Roll back the flag so a retry can attempt delivery again.
            $purchase->forceFill([$column => null])->save();
            AuditLogger::record($action.'.failed', $purchase, after: ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
