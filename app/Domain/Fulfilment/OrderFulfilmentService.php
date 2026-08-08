<?php

namespace App\Domain\Fulfilment;

use App\Domain\Credits\AccountCreditCheckoutService;
use App\Domain\Notifications\BuyerOrderNotifier;
use App\Models\Purchase;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Transitions a Purchase between fulfilment states and stores the
 * provisioned credentials + setup instructions that the buyer sees on the
 * public order status page.
 *
 * All transitions are audited. Illegal transitions throw DomainException.
 */
class OrderFulfilmentService
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'payment_received' => ['pending_setup', 'in_progress', 'cancelled', 'refunded'],
        'pending_setup' => ['in_progress', 'cancelled', 'refunded'],
        'in_progress' => ['awaiting_customer', 'completed', 'cancelled', 'refunded'],
        'awaiting_customer' => ['in_progress', 'completed', 'cancelled', 'refunded'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
        // Legacy tolerance.
        'unfulfilled' => ['payment_received', 'pending_setup', 'in_progress', 'cancelled', 'refunded'],
        'fulfilled' => ['refunded'],
    ];

    public function markPaymentReceived(Purchase $purchase): void
    {
        if ($purchase->fulfilment_status === 'payment_received') {
            return;
        }
        $before = ['status' => $purchase->fulfilment_status];
        $purchase->forceFill([
            'fulfilment_status' => 'payment_received',
            'payment_received_at' => $purchase->payment_received_at ?? now(),
            'customer_view_token' => $purchase->customer_view_token ?: Str::random(32),
        ])->save();
        AuditLogger::record('order.payment_received', $purchase, before: $before);
    }

    public function transition(Purchase $purchase, OrderStatus $to, ?User $actor = null): void
    {
        $from = (string) $purchase->fulfilment_status;
        if ($from === $to->value) {
            return;
        }
        $allowed = self::ALLOWED[$from] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw new DomainException(sprintf('Illegal transition %s → %s', $from, $to->value));
        }

        $stampColumn = match ($to) {
            OrderStatus::PendingSetup => null,
            OrderStatus::InProgress => 'setup_started_at',
            OrderStatus::AwaitingCustomer => 'awaiting_customer_at',
            OrderStatus::Completed => 'completed_at',
            OrderStatus::Cancelled => 'cancelled_at',
            OrderStatus::Refunded => 'refunded_at',
            OrderStatus::PaymentReceived => 'payment_received_at',
        };

        $purchase->fulfilment_status = $to->value;
        if ($stampColumn && ! $purchase->{$stampColumn}) {
            $purchase->{$stampColumn} = now();
        }
        // Keep legacy fulfilled_at populated for reporting parity.
        if ($to === OrderStatus::Completed && ! $purchase->fulfilled_at) {
            $purchase->fulfilled_at = now();
            $purchase->fulfilled_by_user_id ??= $actor?->getKey();
        }
        $purchase->save();

        AuditLogger::record(
            action: 'order.transition',
            subject: $purchase,
            before: ['status' => $from],
            after: ['status' => $to->value],
            actor: $actor,
        );

        // Restore Account Credit spent on this purchase (idempotent).
        if ($to === OrderStatus::Refunded) {
            try {
                App::make(AccountCreditCheckoutService::class)
                    ->restoreAfterRefund($purchase, $actor);
            } catch (\Throwable $e) {
                // Do not block the fulfilment transition; ledger restore can be retried.
                report($e);
            }
        }

        // Notify the buyer once fulfilment reaches Completed (idempotent).
        if ($to === OrderStatus::Completed) {
            App::make(BuyerOrderNotifier::class)
                ->sendOrderCompleted($purchase);
        }
    }

    /**
     * @param array{
     *   provisioned_username?: ?string,
     *   provisioned_password?: ?string,
     *   provisioned_expires_on?: ?string,
     *   setup_instructions_md?: ?string,
     *   download_links?: ?array<int, array{label:string,url:string}>,
     *   fulfilment_notes?: ?string,
     * } $data
     */
    public function updateFulfilmentDetails(Purchase $purchase, array $data, ?User $actor = null): void
    {
        $fill = [];
        if (array_key_exists('provisioned_username', $data)) {
            $fill['provisioned_username_enc'] = $data['provisioned_username'] ?: null;
        }
        if (array_key_exists('provisioned_password', $data) && $data['provisioned_password'] !== null && $data['provisioned_password'] !== '') {
            $fill['provisioned_password_enc'] = $data['provisioned_password'];
        }
        if (array_key_exists('provisioned_expires_on', $data)) {
            $fill['provisioned_expires_on'] = $data['provisioned_expires_on'] ?: null;
        }
        if (array_key_exists('setup_instructions_md', $data)) {
            $fill['setup_instructions_md'] = $data['setup_instructions_md'] ?: null;
        }
        if (array_key_exists('download_links', $data)) {
            $fill['download_links'] = $data['download_links'] ?: null;
        }
        if (array_key_exists('fulfilment_notes', $data)) {
            $fill['fulfilment_notes'] = $data['fulfilment_notes'] ?: null;
        }

        if ($fill === []) {
            return;
        }

        $purchase->forceFill($fill)->save();

        AuditLogger::record(
            action: 'order.details_updated',
            subject: $purchase,
            after: ['fields' => array_keys($fill)],
            actor: $actor,
        );
    }
}
