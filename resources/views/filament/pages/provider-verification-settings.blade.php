<x-filament-panels::page>
    <form wire:submit="save" data-testid="provider-verification-form">
        {{ $this->form }}

        <div style="margin-top:1.5rem;display:flex;gap:.5rem">
            <x-filament::button type="submit" data-testid="provider-verification-save">Save changes</x-filament::button>
            <x-filament::button color="gray" wire:click="testConnection" data-testid="provider-verification-test">Test connection</x-filament::button>
        </div>
    </form>

    @php $d = $diagnostics ?? []; @endphp
    <div style="margin-top:2rem;background:#0f172a;color:#e2e8f0;border-radius:.75rem;padding:1.25rem" data-testid="provider-verification-diagnostics">
        <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:.75rem">Diagnostics (read-only)</div>
        <dl style="display:grid;grid-template-columns:220px 1fr;gap:.4rem 1.5rem;margin:0;font-size:.9rem">
            <dt style="color:#94a3b8">Current driver</dt>
            <dd style="margin:0" data-testid="diag-driver">{{ $d['current_driver'] ?? '—' }}</dd>
            <dt style="color:#94a3b8">Verification enabled</dt>
            <dd style="margin:0" data-testid="diag-enabled">{{ ($d['verification_enabled'] ?? false) ? 'Yes' : 'No' }}</dd>
            <dt style="color:#94a3b8">Last successful verification</dt>
            <dd style="margin:0" data-testid="diag-last-success">{{ $d['last_success_at'] ?? '—' }}</dd>
            <dt style="color:#94a3b8">Last failed verification</dt>
            <dd style="margin:0" data-testid="diag-last-failure">{{ $d['last_failure_at'] ?? '—' }}</dd>
            <dt style="color:#94a3b8">Last verification response code</dt>
            <dd style="margin:0" data-testid="diag-last-code">{{ $d['last_response_code'] ?? '—' }}</dd>
            <dt style="color:#94a3b8">Last note</dt>
            <dd style="margin:0" data-testid="diag-last-note">{{ $d['last_note'] ?? '—' }}</dd>
        </dl>
    </div>
</x-filament-panels::page>
