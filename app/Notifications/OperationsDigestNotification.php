<?php

namespace App\Notifications;

use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Models\OperationsItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Digest reminder mailed to panel admins summarising the currently-open
 * critical/high items in the Operations Centre. Scheduled by the
 * `aio:ops-remind` command.
 */
class OperationsDigestNotification extends Notification
{
    use Queueable;

    /** @param array{critical:int,high:int,overdue:int,unassigned:int,total:int,sample:array<int,OperationsItem>} $summary */
    public function __construct(private readonly array $summary) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;
        $msg = (new MailMessage)
            ->subject('[AIO Ops] '.$s['critical'].' critical, '.$s['high'].' high in the queue')
            ->line('Snapshot of the Operations Centre right now:')
            ->line('• Critical: '.$s['critical'])
            ->line('• High: '.$s['high'])
            ->line('• Overdue: '.$s['overdue'])
            ->line('• Unassigned: '.$s['unassigned'])
            ->line('• Total open: '.$s['total']);

        if (! empty($s['sample'])) {
            $msg->line('Recent priority items:');
            foreach ($s['sample'] as $i) {
                $prio = OperationsPriority::tryFrom($i->priority)?->label() ?? $i->priority;
                $msg->line("- [{$prio}] {$i->title}");
            }
        }

        return $msg->action('Open Operations Centre', url('/admin/operations'));
    }
}
