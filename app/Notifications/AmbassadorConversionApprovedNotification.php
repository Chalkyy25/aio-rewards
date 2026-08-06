<?php

namespace App\Notifications;

use App\Models\ReferralConversion;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AmbassadorConversionApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly ReferralConversion $conversion) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = '£'.number_format($this->conversion->amount_minor / 100, 2);

        return (new MailMessage)
            ->subject('Referral approved — '.$amount)
            ->greeting('Hi '.$notifiable->name.',')
            ->line("A referral you sent has just been approved for **{$amount}**.")
            ->line('Referral code: '.$this->conversion->referral_code_snapshot)
            ->action('Open My Rewards', url('/ambassador/dashboard'))
            ->line('Rewards are calculated once you cross each milestone. Keep sharing!')
            ->salutation('— the AIO Media team');
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversion.approved',
            'conversion_id' => $this->conversion->id,
            'referral_code' => $this->conversion->referral_code_snapshot,
            'amount_minor' => $this->conversion->amount_minor,
            'currency' => $this->conversion->currency,
            'approved_at' => optional($this->conversion->approved_at)->toIso8601String(),
        ];
    }
}
