<div class="ambassador-security" data-testid="ambassador-security-page" style="max-width:720px;margin:2rem auto;padding:0 1rem">
    <h1 style="font-size:1.5rem;margin:0 0 .5rem">Account security</h1>
    <p style="color:#64748b;margin:0 0 1.5rem">Turn on two-factor authentication for an extra layer of protection on your account.</p>

    @if ($flash)
        <div data-testid="security-flash"
             style="padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;
                    background:{{ $flashKind === 'success' ? '#dcfce7' : '#fee2e2' }};
                    color:{{ $flashKind === 'success' ? '#065f46' : '#991b1b' }}">
            {{ $flash }}
        </div>
    @endif

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
            <div>
                <div style="font-weight:600">Two-factor authentication</div>
                <div style="color:#64748b;font-size:.9rem">
                    Status:
                    <strong data-testid="mfa-status">{{ $mfaEnabled ? 'Enabled' : 'Disabled' }}</strong>
                </div>
            </div>
            @if (! $mfaEnabled && ! $showEnrolment)
                <button type="button" wire:click="startEnrolment" data-testid="btn-enable-mfa"
                        style="padding:.6rem 1rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer">
                    Enable
                </button>
            @endif
        </div>

        @if ($showEnrolment)
            <div style="margin-top:1.5rem;border-top:1px solid #e2e8f0;padding-top:1rem" data-testid="enrolment-panel">
                <p style="margin:0 0 .5rem">Scan the QR code below with your authenticator app, then enter the 6-digit code it shows.</p>
                <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center">
                    <img alt="QR code"
                         src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={{ urlencode($enrolmentQrUri) }}"
                         style="border:1px solid #e2e8f0;border-radius:.5rem;background:#fff">
                    <div style="flex:1;min-width:220px">
                        <div style="font-family:monospace;background:#f1f5f9;padding:.5rem;border-radius:.35rem;font-size:.85rem;word-break:break-all"
                             data-testid="enrolment-secret">{{ $enrolmentSecret }}</div>
                        <label style="display:block;margin-top:.75rem;font-weight:500">Authenticator code
                            <input type="text" inputmode="numeric" wire:model="enrolmentCode" data-testid="input-enrolment-code"
                                   maxlength="6" style="width:100%;padding:.6rem;border:1px solid #cbd5e1;border-radius:.5rem;margin-top:.3rem">
                            @error('enrolmentCode')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror
                        </label>
                        <div style="margin-top:.75rem;display:flex;gap:.5rem">
                            <button type="button" wire:click="confirmEnrolment" data-testid="btn-confirm-mfa"
                                    style="padding:.55rem 1rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer">Confirm</button>
                            <button type="button" wire:click="cancelEnrolment" data-testid="btn-cancel-mfa"
                                    style="padding:.55rem 1rem;background:#fff;color:#0f172a;border:1px solid #cbd5e1;border-radius:.5rem;cursor:pointer">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($mfaEnabled)
            <div style="margin-top:1.5rem;border-top:1px solid #e2e8f0;padding-top:1rem">
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <button type="button" wire:click="regenerateRecoveryCodes" data-testid="btn-regen-recovery"
                            style="padding:.55rem 1rem;background:#fff;color:#0f172a;border:1px solid #cbd5e1;border-radius:.5rem;cursor:pointer">
                        Regenerate recovery codes
                    </button>
                </div>

                <div style="margin-top:1.5rem;background:#fef3c7;border-radius:.5rem;padding:1rem" data-testid="disable-panel">
                    <div style="font-weight:600;color:#92400e;margin-bottom:.5rem">Disable two-factor authentication</div>
                    <label>Confirm your password
                        <input type="password" wire:model="confirmPassword" data-testid="input-disable-password"
                               style="width:100%;padding:.55rem;border:1px solid #d97706;border-radius:.35rem;margin-top:.35rem">
                        @error('confirmPassword')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror
                    </label>
                    <button type="button" wire:click="disableMfa" data-testid="btn-disable-mfa"
                            style="margin-top:.75rem;padding:.55rem 1rem;background:#b91c1c;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer">Disable MFA</button>
                </div>
            </div>
        @endif

        @if ($showRecovery && ! empty($recoveryCodes))
            <div style="margin-top:1.5rem;background:#0f172a;color:#f1f5f9;border-radius:.5rem;padding:1rem" data-testid="recovery-codes-panel">
                <div style="font-weight:600;margin-bottom:.5rem">Recovery codes — save these somewhere safe</div>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.35rem;font-family:monospace;font-size:.9rem">
                    @foreach ($recoveryCodes as $code)
                        <div data-testid="recovery-code">{{ $code }}</div>
                    @endforeach
                </div>
                <div style="margin-top:.75rem;font-size:.8rem;color:#94a3b8">Each code can be used once if you lose access to your authenticator.</div>
            </div>
        @endif
    </div>
</div>
