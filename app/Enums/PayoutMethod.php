<?php

namespace App\Enums;

/**
 * Preferred payout destination for a Rewards Member.
 *
 * Bank Transfer and Account Credit are offered to members.
 * PayPal remains as a legacy enum value for historical rows only —
 * it must not appear in new configuration UIs or validation options.
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

    public function isConfigurable(): bool
    {
        return match ($this) {
            self::BankTransfer, self::AccountCredit => true,
            self::PayPal => false,
        };
    }

    /**
     * Methods members may newly select / save.
     *
     * @return list<self>
     */
    public static function configurableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case->isConfigurable(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function configurableOptions(): array
    {
        $out = [];
        foreach (self::configurableCases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        // Prefer configurable options for any UI selection list.
        return self::configurableOptions();
    }
}
