<?php

namespace App\Filament\Pages;

use App\Domain\Settings\SettingsRepository;
use App\Enums\Role as RoleEnum;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(RoleEnum::SuperAdmin->value) ?? false;
    }

    public function mount(): void
    {
        $repo = app(SettingsRepository::class);
        $data = [];
        foreach ($repo->schema() as $key => $meta) {
            $data[$this->flatKey($key)] = $repo->value($key);
        }
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $repo = app(SettingsRepository::class);
        $groups = ['branding' => 'Branding', 'public' => 'Public page content', 'orders' => 'Customer order messages'];

        $sections = [];
        foreach ($groups as $group => $title) {
            $components = [];
            foreach ($repo->schema() as $key => $meta) {
                if (($meta['group'] ?? null) !== $group) {
                    continue;
                }
                $flat = $this->flatKey($key);
                $components[] = ($meta['textarea'] ?? false)
                    ? Textarea::make($flat)->label($meta['label'])->rows(3)->maxLength(4000)
                        ->helperText('Default: '.\Illuminate\Support\Str::limit((string) $meta['default'], 100))
                    : TextInput::make($flat)->label($meta['label'])->maxLength(500)
                        ->helperText('Default: '.\Illuminate\Support\Str::limit((string) $meta['default'], 100));
            }
            $sections[] = Section::make($title)->schema($components)->columns(1);
        }

        return $schema->components($sections)->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $repo = app(SettingsRepository::class);
        $pairs = [];
        foreach ($repo->schema() as $key => $meta) {
            $flat = $this->flatKey($key);
            $pairs[$key] = $state[$flat] ?? null;
        }
        $repo->putMany($pairs, Auth::user());

        Notification::make()->title('Settings saved')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    private function flatKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
