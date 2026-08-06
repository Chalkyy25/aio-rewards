<?php

namespace App\Notifications;

use App\Models\AmbassadorProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Post-verification welcome email. Dispatched EXACTLY ONCE per ambassador,
 * by SendAmbassadorWelcomeAfterVerified in response to Laravel's
 * Illuminate\Auth\Events\Verified event. Idempotency is guaranteed by the
 * atomic UPDATE on users.welcome_email_sent_at inside the listener — this
 * notification itself is intentionally dumb about "have I been sent before".
 */
class AmbassadorWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AmbassadorProfile $ambassador) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to AIO Rewards')
            ->greeting('Welcome, '.$this->ambassador->user->name.'!')
            ->line('Your AIO Rewards account is ready.')
            ->line('Share this referral link with friends and family — when they buy an AIO Media package, you earn rewards:')
            ->line('**'.$this->ambassador->referralUrl().'**')
            ->line('Your unique referral code is: **'.$this->ambassador->referral_code.'**')
            ->action('Open your dashboard', url(route('ambassador.dashboard')));
    }
}
