<?php

namespace App\Listeners;

use App\Domain\Rewards\MilestoneUnlockNotifier;
use App\Notifications\MilestoneUnlockedNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Marks the idempotency record as sent only after Laravel reports a
 * successful channel delivery for MilestoneUnlockedNotification.
 */
class MarkMilestoneUnlockNotificationSent
{
    public function __construct(
        private readonly MilestoneUnlockNotifier $notifier,
    ) {}

    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;
        if (! $notification instanceof MilestoneUnlockedNotification) {
            return;
        }

        // Prefer mail channel as the delivery signal; database channel
        // alone must not finalise "sent" if mail is also configured.
        if ($event->channel !== 'mail') {
            return;
        }

        $key = $notification->snapshot->idempotencyKey;
        if ($key === '') {
            return;
        }

        $this->notifier->markSent($key);

        AuditLogger::record('notification.milestone_unlock.sent', null, after: [
            'idempotency_key' => $key,
            'channel' => $event->channel,
            'tier_id' => $notification->snapshot->tierId,
            'cycle_number' => $notification->snapshot->cycleNumber,
        ]);
    }
}
