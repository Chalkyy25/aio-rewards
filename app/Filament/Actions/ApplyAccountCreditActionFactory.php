<?php

namespace App\Filament\Actions;

use App\Domain\Credits\AccountCreditFulfilmentService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Rewards\RewardFundingIntegrityException;
use App\Enums\PayoutMethod;
use App\Models\Reward;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Admin action: atomically credit the member's Account Credit ledger and
 * mark the reward paid. Distinct from Bank Transfer "Mark paid".
 */
final class ApplyAccountCreditActionFactory
{
    public static function make(string $name = 'applyAccountCredit'): Action
    {
        return Action::make($name)
            ->label(fn (Reward $r) => 'Apply '.$r->accountCreditTotalFormatted().' Account Credit')
            ->icon('heroicon-o-wallet')
            ->color('success')
            ->visible(function (Reward $r): bool {
                if ($r->status !== 'approved') {
                    return false;
                }

                return $r->fulfilmentPayoutMethod() === PayoutMethod::AccountCredit;
            })
            ->modalHeading(fn (Reward $r) => 'Apply '.$r->accountCreditTotalFormatted().' Account Credit')
            ->modalDescription(function (Reward $r) {
                $member = $r->ambassadorProfile?->user;
                $name = $member?->name ?? 'Unknown member';
                $email = $member?->email ?? '';
                $ledger = app(AccountCreditLedger::class);
                $profile = $r->ambassadorProfile;
                $current = $profile ? $ledger->balanceMinor($profile) : 0;
                $bonus = $r->accountCreditBonusMinor();
                $resulting = $current + $r->accountCreditTotalMinor();
                $fmt = static function (int $minor): string {
                    return '£'.number_format($minor / 100, 2);
                };

                $bonusLine = $bonus > 0
                    ? 'Base reward: '.$r->amountFormatted().'. Milestone bonus: '.$r->accountCreditBonusFormatted().'. '
                    : 'Base reward: '.$r->amountFormatted().' (no milestone bonus). ';

                return 'Member: '.$name.($email !== '' ? " ({$email})" : '').'. '
                    .$bonusLine
                    .'Total Account Credit: '.$r->accountCreditTotalFormatted().'. '
                    .'Current balance: '.$fmt($current).'. '
                    .'Resulting balance: '.$fmt($resulting).'. '
                    .'This immediately credits their AIO account balance and marks the reward paid. '
                    .'Do not perform this action twice — the system blocks duplicate credits.';
            })
            ->modalSubmitActionLabel('Confirm Account Credit')
            ->schema([
                Textarea::make('note')
                    ->label('Admin note')
                    ->maxLength(500)
                    ->helperText('Optional internal note.'),
            ])
            ->action(function (Reward $r, array $data, AccountCreditFulfilmentService $service) {
                try {
                    $ok = $service->apply($r, Auth::user(), $data['note'] ?? null);
                } catch (RewardFundingIntegrityException|\InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Cannot apply Account Credit')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($ok) {
                    Notification::make()
                        ->title('Account Credit applied')
                        ->body($r->fresh()->accountCreditTotalFormatted().' credited and reward marked paid.')
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Account Credit not applied')
                        ->body('Reward was not in an approvable paid-awaiting state.')
                        ->warning()
                        ->send();
                }
            });
    }
}
