<x-filament-panels::page>
    <form wire:submit="save" data-testid="admin-settings-form">
        {{ $this->form }}

        <div style="margin-top:1.5rem;display:flex;gap:.5rem">
            <x-filament::button type="submit" data-testid="admin-settings-save">Save changes</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
