<?php

namespace App\Notifications;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminOrderReceivedNotification extends Notification
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
        $ref = $this->purchase->orderReference();

        $msg = (new MailMessage)
            ->subject("[AIO Rewards] New paid order — {$ref}")
            ->greeting('New order received')
            ->line("Order **{$ref}** has been paid and is awaiting fulfilment.")
            ->line('Package: '.$this->purchase->package->name)
            ->line('Amount: '.$this->purchase->priceFormatted())
            ->line('Buyer: '.$this->purchase->buyer_name.' — '.$this->purchase->buyer_email)
            ->line('Preferred username: '.$this->purchase->preferred_username)
            ->line('Delivery method: '.ucfirst($this->purchase->delivery_method));

        if ($this->purchase->referral_code_snapshot) {
            $msg->line('Referred by ambassador code: '.$this->purchase->referral_code_snapshot);
        }

        return $msg
            ->action('Open order in admin', url('/admin/orders/'.$this->purchase->id))
            ->line('Please complete provisioning and mark the order as Completed.');
    }
}
