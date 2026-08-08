<?php

namespace App\Enums;

/**
 * Preferred payout destination for a Rewards Member.
 *
 * Designed as a closed enum so new methods can be added later without
 * reshaping the MemberPayoutProfile storage model.
 */
enum PayoutMethod: string
{
    case BankTransfer = 'bank_transfer';
    case PayPal = 'paypal';
    case AccountCredit = 'account_credit';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank Transfer',
            self::PayPal => 'PayPal',
            self::AccountCredit => 'Account Credit',
        };
    }

    public function storesSensitiveDestination(): bool
    {
        return match ($this) {
            self::BankTransfer, self::PayPal => true,
            self::AccountCredit => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
