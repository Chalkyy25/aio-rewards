<?php

namespace App\Jobs;

use App\Domain\Billing\StripeEventProcessor;
use App\Models\StripeEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessStripeEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $eventId) {}

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(StripeEventProcessor $proc): void
    {
        $ev = StripeEvent::where('stripe_event_id', $this->eventId)->first();
        if ($ev) {
            $proc->process($ev);
        }
    }
}
