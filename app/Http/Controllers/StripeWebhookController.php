<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeEventJob;
use App\Models\StripeEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('stripe.webhook_secret');
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature', '');

        if ($secret === '') {
            return response('Webhook secret not configured', 503);
        }

        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (\Throwable $e) {
            return response('Invalid signature', 400);
        }

        $stored = StripeEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            [
                'type' => $event->type,
                'livemode' => (bool) ($event->livemode ?? false),
                'payload' => json_decode(json_encode($event), true),
                'signature_verified' => true,
            ]
        );

        // Dispatch async; unique on stripe_event_id.
        ProcessStripeEventJob::dispatch($stored->stripe_event_id)->onQueue('webhooks');

        return response('OK', 200);
    }
}
