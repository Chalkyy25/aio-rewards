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
        'payment_received' => ['pending_setup', 'in_progress', 'completed', 'cancelled', 'refunded'],
        'pending_setup' => ['in_progress', 'completed', 'cancelled', 'refunded'],
        'in_progress' => ['awaiting_customer', 'completed', 'cancelled', 'refunded'],
        'awaiting_customer' => ['in_progress', 'completed', 'cancelled', 'refunded'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
        // Legacy tolerance.
        'unfulfilled' => ['payment_received', 'pending_setup', 'in_progress', 'cancelled', 'refunded'],
        'fulfilled' => ['refunded'],
    ];

    /** Fulfilment states from which an admin may complete a paid order. */
    private const COMPLETABLE = [
        'payment_received',
        'pending_setup',
        'in_progress',
        'awaiting_customer',
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

    /**
     * Whether the Filament "Complete Order" action should be shown.
     * Does not check credentials — those are validated on execute.
     */
    public function isEligibleForCompleteAction(Purchase $purchase): bool
    {
        return $purchase->status === 'paid'
            && in_array((string) $purchase->fulfilment_status, self::COMPLETABLE, true);
    }

    /**
     * Requirements that must be satisfied before an order can become Completed.
     *
     * Package only stores free-text `duration_label`, so expiry cannot be
     * required reliably per product type. Username + password are always
     * required for AIO Media provisioned subscriptions.
     *
     * @return list<string>
     */
    public function missingCompletionRequirements(Purchase $purchase): array
    {
        $missing = [];

        if ($purchase->status !== 'paid') {
            $missing[] = 'payment must be paid';
        }

        if (! filled($purchase->provisioned_username_enc)) {
            $missing[] = 'provisioned username';
        }

        if (! filled($purchase->provisioned_password_enc)) {
            $missing[] = 'provisioned password';
        }

        return $missing;
    }

    /**
     * @throws DomainException when payment or required credentials are missing
     */
    public function assertReadyToComplete(Purchase $purchase): void
    {
        $missing = $this->missingCompletionRequirements($purchase);
        if ($missing === []) {
            return;
        }

        throw new DomainException(
            'Cannot complete order: missing '.implode(', ', $missing).'.'
        );
    }

    public function transition(Purchase $purchase, OrderStatus $to, ?User $actor = null, bool $restoreCredit = true): void
    {
        $from = (string) $purchase->fulfilment_status;
        if ($from === $to->value) {
            return;
        }
        $allowed = self::ALLOWED[$from] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw new DomainException(sprintf('Illegal transition %s → %s', $from, $to->value));
        }

        if ($to === OrderStatus::Completed) {
            $this->assertReadyToComplete($purchase);
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

        // Admin/ops refund path: restore AC for full clawback when caller requests it.
        // Stripe webhooks pass restoreCredit=false and apply amount-aware restoration themselves.
        if ($to === OrderStatus::Refunded && $restoreCredit) {
            try {
                App::make(AccountCreditCheckoutService::class)
                    ->restoreFullyCreditedPurchase($purchase, $actor);
            } catch (\Throwable $e) {
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
