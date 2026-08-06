<?php

namespace App\Notifications;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the buyer once Stripe confirms the payment. Contains no
 * secrets — only the opaque customer_view_token link.
 */
class BuyerPaymentReceivedNotification extends Notification
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
        $leadLine = (string) settings('orders.payment_received_lead');

        $msg = (new MailMessage)
            ->subject("Payment received — {$ref}")
            ->greeting('Hi '.$this->purchase->buyer_name.',')
            ->line($leadLine)
            ->line('**Order:** '.$ref)
            ->line('**Package:** '.$this->purchase->package->name.' — '.$this->purchase->priceFormatted())
            ->line('**Preferred username:** '.$this->purchase->preferred_username)
            ->line('**Current status:** '.$this->purchase->statusLabel());

        if ($statusUrl) {
            $msg->action('Track your order', $statusUrl)
                ->line('Please **bookmark this link** — it is the only place your order details will appear. Do not share it publicly.');
        } else {
            $msg->line('You will receive a follow-up email once fulfilment begins.');
        }

        return $msg
            ->line('Need help? Reply to this email or contact '.$support.'.')
            ->salutation('Thanks — the AIO Media team');
    }
}
