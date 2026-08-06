<?php

namespace App\Notifications;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the buyer when the order reaches the Completed fulfilment state.
 * The final assigned username (if set) is included as a courtesy so the
 * buyer knows what to expect; the password is NEVER emailed — it is
 * revealed only on the token-protected status page.
 */
class BuyerOrderCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Purchase $purchase) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusUrl = $this->purchase->customer_view_token
            ? url('/order/'.$this->purchase->customer_view_token)
            : null;
        $support = (string) (settings('brand.support_email') ?: config('support.contact_email') ?: config('mail.from.address'));
        $ref = $this->purchase->orderReference();
        $leadLine = (string) settings('orders.completed_lead');
        $securityReminder = (string) settings('orders.security_reminder');

        $msg = (new MailMessage)
            ->subject((settings('brand.name') ?: 'AIO Rewards').' — your access is ready — '.$ref)
            ->greeting('Hi '.$this->purchase->buyer_name.',')
            ->line('**'.$leadLine.'**')
            ->line('**Order:** '.$ref)
            ->line('**Package:** '.$this->purchase->package->name);

        if ($this->purchase->provisioned_username_enc) {
            $msg->line('**Your assigned username:** '.$this->purchase->provisioned_username_enc);
        }

        if ($statusUrl) {
            $msg->action('View your credentials & setup', $statusUrl)
                ->line($securityReminder)
                ->line('Please save your credentials somewhere secure and do not share this link.');
        } else {
            $msg->line('Please contact support to retrieve your access details.');
        }

        return $msg
            ->line('Need help? Contact '.$support.'.')
            ->salutation('Enjoy — the AIO Media team');
    }
}
