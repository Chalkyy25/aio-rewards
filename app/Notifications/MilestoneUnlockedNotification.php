<?php

namespace App\Notifications;

use App\Domain\Rewards\MilestoneUnlockNotifier;
use App\Domain\Rewards\MilestoneUnlockSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic milestone-unlock notification. Receives a typed progression /
 * tier snapshot — never hardcodes thresholds or amounts.
 */
class MilestoneUnlockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MilestoneUnlockSnapshot $snapshot,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->snapshot;
        $amount = $s->rewardAmountFormatted();
        $intro = trim((string) settings('notifications.milestone_unlock_intro'));
        $saveGrowHelper = trim((string) settings('notifications.milestone_unlock_save_grow_helper'));

        $subject = $s->bonusAmountMinor > 0
            ? "You've unlocked {$amount} 🎉"
            : "You've unlocked a {$amount} reward 🎉";

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$s->firstName().',');

        if ($intro !== '') {
            $mail->line($intro);
        }

        if ($s->bonusAmountMinor > 0) {
            $mail->line(
                "You saved your first reward and reached {$s->threshold} approved referrals."
            );
            $mail->line(
                "Your reward has grown to {$amount}, including your Save & Grow bonus."
            );
            if ($saveGrowHelper !== '') {
                $mail->line($saveGrowHelper);
            } else {
                $mail->line("It's now available to claim in AIO Rewards.");
            }
            $mail->action('View My Rewards', route('ambassador.milestones'));
        } else {
            $mail->line(
                "You've reached {$s->threshold} approved referrals and unlocked a {$amount} reward."
            );
            $mail->line('You can cash out now, or keep building.');

            if ($s->nextThreshold !== null && $s->nextRewardAmountFormatted() !== null) {
                $nextAmount = $s->nextRewardAmountFormatted();
                $mail->line(
                    "Reach {$s->nextThreshold} approved referrals before cashing out and your reward grows to {$nextAmount}."
                );
                if ($s->nextBonusAmountMinor !== null && $s->nextBonusAmountMinor > 0) {
                    $bonus = $s->nextBonusAmountFormatted();
                    $mail->line("That's an extra {$bonus} Save & Grow bonus.");
                }
            }

            $mail->action('View My Rewards', route('ambassador.milestones'));
            $mail->line('[View Reward Milestones]('.route('ambassador.milestones').')');
        }

        return $mail->salutation('— the AIO Media team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $s = $this->snapshot;

        return [
            'type' => 'milestone.unlocked',
            'idempotency_key' => $s->idempotencyKey,
            'cycle_number' => $s->cycleNumber,
            'tier_id' => $s->tierId,
            'threshold' => $s->threshold,
            'total_reward_amount_minor' => $s->totalRewardAmountMinor,
            'bonus_amount_minor' => $s->bonusAmountMinor,
            'currency' => $s->currency,
            'title' => $s->title,
            'eligible_count' => $s->eligibleCount,
            'next_threshold' => $s->nextThreshold,
            'rewards_url' => route('ambassador.milestones'),
        ];
    }

    public function failed(?\Throwable $e = null): void
    {
        if ($this->snapshot->idempotencyKey === '') {
            return;
        }

        app(MilestoneUnlockNotifier::class)->markFailed(
            $this->snapshot->idempotencyKey,
            $e ? $e::class : 'Exception',
        );
    }
}
