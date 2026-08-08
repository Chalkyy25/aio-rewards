<?php

namespace App\Filament\Resources\AmbassadorResource\Pages;

use App\Enums\PayoutMethod;
use App\Filament\Resources\AmbassadorResource;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Support\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Gate;

class ViewAmbassador extends ViewRecord
{
    protected static string $resource = AmbassadorResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('revealPayoutDetails')
                ->label('Reveal payout details')
                ->icon('heroicon-o-eye')
                ->color('warning')
                ->visible(function (): bool {
                    /** @var AmbassadorProfile $record */
                    $record = $this->getRecord();
                    $user = Auth::user();
                    $payout = $record->payoutProfile;

                    return $user !== null
                        && $payout !== null
                        && $payout->preferred_method->storesSensitiveDestination()
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
                ->modalDescription('This action is audited. Support users cannot perform it. Revealed values are shown once and are never written to the audit log.')
                ->action(function (array $data): void {
                    /** @var AmbassadorProfile $record */
                    $record = $this->getRecord();
                    /** @var \App\Models\User $actor */
                    $actor = Auth::user();
                    /** @var MemberPayoutProfile|null $payout */
                    $payout = $record->payoutProfile;

                    abort_unless($payout && Gate::forUser($actor)->allows('reveal', $payout), 403);

                    if (! Hash::check((string) $data['password'], (string) $actor->password)) {
                        Notification::make()
                            ->title('Password did not match')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Audit safe metadata only — never the revealed value.
                    AuditLogger::record(
                        action: 'payout_profile.details_revealed',
                        subject: $payout,
                        actor: $actor,
                        context: [
                            'ambassador_profile_id' => $record->id,
                            'method' => $payout->preferred_method->value,
                            'reason' => (string) $data['reason'],
                        ],
                    );

                    $body = match ($payout->preferred_method) {
                        PayoutMethod::BankTransfer => implode("\n", array_filter([
                            'Account holder: '.($payout->account_holder_name ?? '—'),
                            'Sort code: '.($payout->sort_code ?? '—'),
                            'Account number: '.($payout->account_number ?? '—'),
                        ])),
                        PayoutMethod::PayPal => 'PayPal email: '.($payout->paypal_email ?? '—'),
                        default => 'No sensitive destination stored.',
                    };

                    Notification::make()
                        ->title('Payout details (one-time reveal)')
                        ->body($body)
                        ->warning()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
