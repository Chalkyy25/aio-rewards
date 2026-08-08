<?php

namespace App\Filament\Actions;

use App\Domain\Payouts\RevealedPayoutDetailsStore;
use App\Domain\Payouts\RevealPayoutDetailsService;
use App\Models\MemberPayoutProfile;
use App\Models\Reward;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Shared Ambassador / Reward admin actions for secure payout reveal.
 *
 * Flow: confirmation modal (reason + password) → audited decrypt →
 * "Payout details" modal. Plaintext lives only in a request-scoped store
 * for the modal render — never in a toast or Livewire public property.
 */
final class RevealPayoutDetailsActionFactory
{
    public const REVEAL_ACTION = 'revealPayoutDetails';

    public const SHOW_ACTION = 'showRevealedPayoutDetails';

    /**
     * @param callable(): (?MemberPayoutProfile) $resolveProfile
     * @param callable(): (?Reward)|null $resolveReward
     * @param callable(): bool|null $additionalVisible
     */
    public static function reveal(
        callable $resolveProfile,
        string $source,
        ?callable $resolveReward = null,
        ?callable $additionalVisible = null,
    ): Action {
        return Action::make(self::REVEAL_ACTION)
            ->label('Reveal payout details')
            ->icon('heroicon-o-eye')
            ->color('warning')
            ->visible(function () use ($resolveProfile, $additionalVisible): bool {
                if ($additionalVisible && ! $additionalVisible()) {
                    return false;
                }

                $user = Auth::user();
                $payout = $resolveProfile();

                return $user instanceof User
                    && $payout instanceof MemberPayoutProfile
                    && $payout->preferred_method->storesSensitiveDestination()
                    && $payout->isConfigured()
                    && Gate::forUser($user)->allows('reveal', $payout);
            })
            ->schema([
                Textarea::make('reason')
                    ->label('Reason for reveal')
                    ->required()
                    ->maxLength(500)
                    ->helperText('Recorded in the audit log. Never include the revealed values.'),
                TextInput::make('password')
                    ->label('Confirm your password')
                    ->password()
                    ->required()
                    ->revealable(),
            ])
            ->modalHeading('Reveal sensitive payout details')
            ->modalDescription('This action is audited. Support users cannot perform it. Revealed values are shown once in a secure modal and are never written to the audit log.')
            ->action(function (array $data, Component $livewire) use ($resolveProfile, $resolveReward, $source): void {
                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);

                $payout = $resolveProfile();
                abort_unless($payout instanceof MemberPayoutProfile, 404);

                $reward = $resolveReward ? $resolveReward() : null;

                try {
                    app(RevealPayoutDetailsService::class)->reveal(
                        profile: $payout,
                        actor: $actor,
                        password: (string) ($data['password'] ?? ''),
                        reason: (string) ($data['reason'] ?? ''),
                        source: $source,
                        reward: $reward instanceof Reward ? $reward : null,
                    );
                } catch (AuthorizationException) {
                    abort(403);
                } catch (ValidationException $e) {
                    $messages = collect($e->errors())->flatten()->implode(' ');
                    Notification::make()
                        ->title('Unable to reveal payout details')
                        ->body($messages !== '' ? $messages : 'Validation failed.')
                        ->danger()
                        ->send();

                    return;
                }

                if (method_exists($livewire, 'replaceMountedAction')) {
                    $livewire->replaceMountedAction(self::SHOW_ACTION);
                }
            });
    }

    public static function showModal(): Action
    {
        return Action::make(self::SHOW_ACTION)
            ->label('Payout details')
            ->modalHeading('Payout details')
            ->modalContent(function () {
                $details = app(RevealedPayoutDetailsStore::class)->peek();

                return view('filament.modals.revealed-payout-details', [
                    'details' => $details,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(true)
            ->extraModalFooterActions([])
            ->action(function (): void {
                app(RevealedPayoutDetailsStore::class)->clear();
            })
            // Only visible while a successful reveal is waiting to be shown.
            // Using hidden() would also disable mounting (Filament treats hidden
            // actions as disabled), which breaks the reveal → modal chain.
            ->visible(fn (): bool => app(RevealedPayoutDetailsStore::class)->peek() !== null);
    }
}
