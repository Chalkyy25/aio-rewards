<?php

namespace App\Domain\Fulfilment;

/**
 * Fulfilment lifecycle for a paid purchase. Stored as a plain string in
 * `purchases.fulfilment_status` for portability; use this enum in code.
 *
 * Legacy values `unfulfilled` and `fulfilled` are still tolerated in the DB
 * for backward compatibility but new code should always use these members.
 */
enum OrderStatus: string
{
    case PaymentReceived = 'payment_received';
    case PendingSetup = 'pending_setup';
    case InProgress = 'in_progress';
    case AwaitingCustomer = 'awaiting_customer';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PaymentReceived => 'Payment received',
            self::PendingSetup => 'Pending setup',
            self::InProgress => 'In progress',
            self::AwaitingCustomer => 'Awaiting customer',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PaymentReceived => 'info',
            self::PendingSetup => 'gray',
            self::InProgress => 'warning',
            self::AwaitingCustomer => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Refunded => 'danger',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Refunded], true);
    }
}
