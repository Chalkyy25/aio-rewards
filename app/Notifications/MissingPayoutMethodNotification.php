<?php

namespace App\Notifications;

use App\Models\Reward;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingPayoutMethodNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Reward $reward) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = $this->reward->amountFormatted();

        return (new MailMessage)
            ->subject('Add your payout details to receive your reward')
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your reward of {$amount} is approved and ready for payment.")
            ->line('Add your payout details to receive your reward.')
            ->action('Open Payout Settings', url('/ambassador/payout-settings'))
            ->salutation('— the AIO Media team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payout.missing_method_prompt',
            'reward_id' => $this->reward->id,
            'amount_minor' => $this->reward->amount_minor,
            'currency' => $this->reward->currency,
            // Deliberately omit any bank / PayPal destination fields.
        ];
    }
}
