<?php

namespace App\Filament\Pages;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\XtreamVerificationDriver;
use App\Domain\Settings\SettingsRepository;
use App\Enums\Role as RoleEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;

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
                ->description('When disabled, activation lets every submitted username through without contacting the upstream. Use only for controlled maintenance windows.')
                ->schema([
                    Toggle::make('enabled')->label('Enable verification')->default(true),
                    TextInput::make('display_name')->label('Provider display name')->maxLength(190)->required(),
                ])->columns(2),

            Section::make('Xtream connection')
                ->description('DNS URL is stored in the Settings table — never in .env. Only true secrets belong in environment variables.')
                ->schema([
                    TextInput::make('xtream_dns_url')
                        ->label('Xtream DNS URL')
                        ->helperText('Example: https://iptv.example.com  — will hit {url}/player_api.php')
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

    public function testConnection(): void
    {
        $repo = app(SettingsRepository::class);
        $dns = trim((string) $repo->value('provider.xtream_dns_url'));
        if ($dns === '') {
            Notification::make()->title('No DNS URL configured')->danger()->send();

            return;
        }

        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $repo->value('provider.active_status_values')))));
        $driver = new XtreamVerificationDriver(
            http: app(HttpFactory::class),
            settings: $repo,
            dnsUrl: $dns,
            timeout: (int) ($repo->value('provider.timeout_seconds') ?? '8'),
            activeStatusValues: $statuses === [] ? ['Active'] : $statuses,
        );

        $probe = $driver->probeConnection();
        if ($probe['ok']) {
            Notification::make()
                ->title('Xtream DNS reachable')
                ->body('HTTP '.$probe['http_status'].' — '.$probe['note'])
                ->success()->send();
        } else {
            Notification::make()
                ->title('Test failed')
                ->body('HTTP '.($probe['http_status'] ?? 'n/a').' — '.$probe['note'])
                ->danger()->send();
        }
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
