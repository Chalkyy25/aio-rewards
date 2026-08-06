<?php

namespace App\Filament\Pages;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\Enums\VerificationFailureReason;
use App\Domain\Provider\Exceptions\ProviderUnavailableException;
use App\Domain\Settings\SettingsRepository;
use App\Enums\Role as RoleEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use SensitiveParameter;

class ProviderVerificationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Provider Verification';

    protected static ?string $title = 'Provider Verification';

    protected static ?string $slug = 'settings/provider-verification';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.provider-verification-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(RoleEnum::SuperAdmin->value) ?? false;
    }

    public function mount(): void
    {
        $repo = app(SettingsRepository::class);
        $this->form->fill([
            'enabled' => (bool) (int) ($repo->value('provider.enabled') ?? '1'),
            'display_name' => (string) $repo->value('provider.display_name'),
            'xtream_dns_url' => (string) $repo->value('provider.xtream_dns_url'),
            'timeout_seconds' => (int) ($repo->value('provider.timeout_seconds') ?? '8'),
            'active_status_values' => (string) $repo->value('provider.active_status_values'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Verification behaviour')
                ->description('When disabled, activation is halted with a temporarily-unavailable message. Use only for controlled maintenance windows.')
                ->schema([
                    Toggle::make('enabled')->label('Enable verification')->default(true),
                    TextInput::make('display_name')->label('Provider display name')->maxLength(190)->required(),
                ])->columns(2),

            Section::make('Xtream connection')
                ->description('DNS URL is stored in the Settings table — never in .env. Only true secrets belong in environment variables.')
                ->schema([
                    TextInput::make('xtream_dns_url')
                        ->label('Xtream DNS URL')
                        ->helperText('Example: https://iptv.example.com — will hit {url}/player_api.php')
                        ->url()
                        ->maxLength(500),
                    TextInput::make('timeout_seconds')
                        ->label('Connection timeout (seconds)')
                        ->numeric()->minValue(2)->maxValue(30)->default(8)->required(),
                    TextInput::make('active_status_values')
                        ->label('Active status values')
                        ->helperText('Comma-separated. Upstream user_info.status values that count as active. Default: Active')
                        ->maxLength(500)->default('Active'),
                ])->columns(3),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        app(SettingsRepository::class)->putMany([
            'provider.enabled' => ! empty($state['enabled']) ? '1' : '0',
            'provider.display_name' => (string) ($state['display_name'] ?? ''),
            'provider.xtream_dns_url' => rtrim((string) ($state['xtream_dns_url'] ?? ''), '/'),
            'provider.timeout_seconds' => (string) (int) ($state['timeout_seconds'] ?? 8),
            'provider.active_status_values' => (string) ($state['active_status_values'] ?? 'Active'),
        ], Auth::user());

        Notification::make()->title('Provider verification settings saved')->success()->send();
    }

    /**
     * "Test Connection" — routes through the SAME container binding used by
     * the ambassador activation flow (`CustomerVerificationContract`). Prompts
     * the operator for a one-shot probe username/password so we exercise the
     * real authenticated path, not a special "probe" endpoint.
     *
     * The credentials:
     *   • exist only inside this action closure ($data is not persisted to
     *     Livewire state — it's the ephemeral modal form payload);
     *   • are consumed once by verifyActiveCustomer();
     *   • are explicitly `unset()` before the notification is rendered so
     *     they cannot leak into subsequent renders, exceptions or logs.
     *
     * All diagnostics writes (last_success_at / last_response_code / last_note)
     * are performed by the driver itself (single source of truth); this method
     * only classifies the result for display.
     */
    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->color('gray')
            ->modalHeading('Test Provider Verification')
            ->modalSubmitActionLabel('Run test')
            ->modalDescription('Enter a real Xtream credential pair. The values are used once for this probe and are not stored, logged, audited or cached.')
            ->schema([
                TextInput::make('probe_username')
                    ->label('Xtream username')
                    ->required()
                    ->autocomplete('off')
                    ->maxLength(190),
                TextInput::make('probe_password')
                    ->label('Xtream password')
                    ->required()
                    ->password()
                    ->revealable(false)
                    ->autocomplete('off')
                    ->maxLength(190),
            ])
            ->action(function (array $data): void {
                $this->runProbe(
                    (string) ($data['probe_username'] ?? ''),
                    (string) ($data['probe_password'] ?? ''),
                );
                // Zero out the modal payload BEFORE we return so it cannot
                // survive on the wire or in any exception rendered by the
                // Livewire error handler. (We also never stored it on $this.)
                unset($data);
            });
    }

    /**
     * Executes exactly one verification through the container-bound driver.
     * Never persists, logs, audits, caches, queues or flashes the creds.
     */
    private function runProbe(#[SensitiveParameter] string $username, #[SensitiveParameter] string $password): void
    {
        if (trim($username) === '' || $password === '') {
            Notification::make()->title('Username and password are required for the probe')->danger()->send();

            return;
        }

        $driver = app(CustomerVerificationContract::class);
        $driverKey = $driver->driverKey();
        $startedAt = microtime(true);

        try {
            $result = $driver->verifyActiveCustomer(new VerifyCustomerRequest($username, $password));
        } catch (ProviderUnavailableException $e) {
            // Driver has already written last_failure_at / last_note.
            // Do NOT include $e->getMessage() in the UI — it may echo upstream response text.
            unset($username, $password);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Notification::make()
                ->title('Provider unavailable')
                ->body('Driver: '.$driverKey.'. HTTP '.(app(SettingsRepository::class)->value('provider.last_response_code') ?? 'n/a').'. Duration: '.$durationMs.' ms. Try again later.')
                ->danger()
                ->send();

            return;
        } catch (\Throwable) {
            // Never surface exception text — it can contain the request body.
            unset($username, $password);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Notification::make()
                ->title('Test failed')
                ->body('Unexpected error contacting the provider. Duration: '.$durationMs.' ms.')
                ->danger()
                ->send();

            return;
        }

        // Discard credentials immediately — the driver's HTTP call has completed.
        unset($username, $password);

        $repo = app(SettingsRepository::class);
        $httpStatus = $repo->value('provider.last_response_code') ?? 'n/a';
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($result->eligible) {
            Notification::make()
                ->title('Success — account is eligible')
                ->body('Driver: '.$driverKey.'. HTTP '.$httpStatus.'. Duration: '.$durationMs.' ms. Status: active.')
                ->success()
                ->send();

            return;
        }

        // Rejection classification — expose only the safe reason enum label.
        $reason = $result->reason ?? VerificationFailureReason::Error;
        Notification::make()
            ->title('Verified — account not eligible')
            ->body('Driver: '.$driverKey.'. HTTP '.$httpStatus.'. Duration: '.$durationMs.' ms. Reason: '.$this->safeReasonLabel($reason))
            ->warning()
            ->send();
    }

    private function safeReasonLabel(VerificationFailureReason $reason): string
    {
        return match ($reason) {
            VerificationFailureReason::WrongCredentials => 'wrong credentials',
            VerificationFailureReason::NotFound => 'account not found',
            VerificationFailureReason::Inactive => 'subscription inactive',
            VerificationFailureReason::Error => 'temporarily unavailable',
        };
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $repo = app(SettingsRepository::class);
        $driver = app(CustomerVerificationContract::class);

        return [
            'diagnostics' => [
                'current_driver' => $driver->driverKey(),
                'verification_enabled' => (bool) (int) ($repo->value('provider.enabled') ?? '1'),
                'last_success_at' => $repo->value('provider.last_success_at'),
                'last_failure_at' => $repo->value('provider.last_failure_at'),
                'last_response_code' => $repo->value('provider.last_response_code'),
                'last_note' => $repo->value('provider.last_note'),
            ],
        ];
    }
}
