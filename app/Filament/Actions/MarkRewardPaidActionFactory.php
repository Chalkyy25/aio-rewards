<?php

namespace App\Filament\Actions;

use App\Domain\Rewards\RewardsEngine;
use App\Enums\PayoutMethod;
use App\Models\Reward;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Shared Mark paid confirmation for Reward table + view pages.
 *
 * Records that an admin manually sent payment outside AIO Rewards.
 */
final class MarkRewardPaidActionFactory
{
    public static function make(string $name = 'markPaid'): Action
    {
        return Action::make($name)
            ->label('Mark paid')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->visible(fn (Reward $r) => $r->status === 'approved')
            ->modalHeading('Confirm payment')
            ->modalDescription(function (Reward $r) {
                $payout = $r->ambassadorProfile?->payoutProfile;
                $methodLabel = ($payout?->preferred_method ?? PayoutMethod::BankTransfer)->label();

                if (! $payout || ! $payout->isConfigured()) {
                    return 'Payment method that will be recorded: '.$methodLabel
                        .'. Warning: this Rewards Member has no payout method configured. Confirm only if you have already paid them by an alternate manual method. AIO Rewards does not send money automatically.';
                }

                return 'Payment method that will be recorded: '.$methodLabel
                    .'. Confirm only after you have manually sent this payment outside AIO Rewards. This does not transfer money automatically.';
            })
            ->modalSubmitActionLabel('Confirm payment')
            ->schema([
                TextInput::make('payment_reference')
                    ->label('Payment reference')
                    ->maxLength(190)
                    ->helperText('Optional bank/payment reference for your records.'),
                Textarea::make('note')
                    ->label('Admin note')
                    ->maxLength(500)
                    ->helperText('Optional internal note. Do not paste full bank details here.'),
            ])
            ->action(function (Reward $r, array $data, RewardsEngine $engine) {
                $method = $r->ambassadorProfile?->payoutProfile?->preferred_method?->value
                    ?: PayoutMethod::BankTransfer->value;

                $engine->markPaid(
                    $r,
                    Auth::user(),
                    $data['note'] ?? null,
                    $method,
                    $data['payment_reference'] ?? null,
                );
                Notification::make()->title('Reward marked paid')->success()->send();
            });
    }
}
