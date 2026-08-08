<div class="ambassador-payout-settings" data-testid="ambassador-payout-settings-page" style="max-width:720px;margin:0 auto">
    <h1 style="font-size:1.5rem;margin:0 0 .5rem">Payout Settings</h1>
    <p style="color:#64748b;margin:0 0 1.5rem">
        Choose how you would like approved rewards to be paid. We never automate payouts from this page —
        your preference helps our team process payments manually.
    </p>

    @if ($flash)
        <div data-testid="payout-flash"
             style="padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;
                    background:{{ $flashKind === 'success' ? '#dcfce7' : '#fee2e2' }};
                    color:{{ $flashKind === 'success' ? '#065f46' : '#991b1b' }}">
            {{ $flash }}
        </div>
    @endif

    @if ($isConfigured)
        <div data-testid="payout-current-summary"
             style="background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1.25rem;margin-bottom:1rem">
            <div style="font-weight:600;margin-bottom:.5rem">Current details</div>
            <div style="color:#334155;font-size:.95rem;line-height:1.6">
                <div>Payout method:
                    <strong data-testid="payout-current-method">{{ $currentMethodLabel }}</strong>
                </div>
                @if ($currentMethodLabel === 'Bank Transfer')
                    <div data-testid="payout-masked-holder">Account holder: {{ $displayAccountHolder }}</div>
                    <div data-testid="payout-masked-sort">Sort code: {{ $maskedSortCode }}</div>
                    <div data-testid="payout-masked-account">Account number: {{ $maskedAccountNumber }}</div>
                @elseif ($isLegacyPayPal)
                    <div data-testid="payout-paypal-email">PayPal (legacy): {{ $maskedPayPalEmail }}</div>
                    <div style="color:#92400e;margin-top:.35rem" data-testid="payout-paypal-legacy-note">
                        PayPal is no longer available. Please update to Bank Transfer or Account Credit.
                    </div>
                @else
                    <div data-testid="payout-account-credit-note">
                        Account Credit — add your reward to your AIO balance to use toward eligible AIO Media purchases or renewals.
                        No bank details are stored.
                        <div style="margin-top:.5rem">
                            <a href="{{ route('ambassador.account-credit') }}" data-testid="payout-credit-balance-link">View Account Credit balance &amp; history</a>
                        </div>
                    </div>
                @endif
                <div style="color:#64748b;margin-top:.35rem" data-testid="payout-last-updated">
                    Last updated: {{ $lastUpdated }}
                </div>
            </div>
        </div>
    @endif

    <form wire:submit="save" style="background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1.5rem">
        <label style="display:block;font-weight:500;margin-bottom:.35rem">Preferred payout method</label>
        <select wire:model.live="preferredMethod" data-testid="input-payout-method"
                style="width:100%;padding:.65rem;border:1px solid #cbd5e1;border-radius:.5rem;background:#fff">
            <option value="">Select a method…</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="account_credit">Account Credit</option>
        </select>
        @error('preferredMethod')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror

        @if ($preferredMethod === 'bank_transfer')
            <div style="margin-top:1.25rem" data-testid="payout-bank-fields">
                <label style="display:block;font-weight:500;margin-bottom:.35rem">Account holder name
                    <input type="text" wire:model="accountHolderName" autocomplete="name"
                           data-testid="input-account-holder"
                           style="width:100%;padding:.65rem;border:1px solid #cbd5e1;border-radius:.5rem;margin-top:.3rem">
                </label>
                @error('accountHolderName')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror

                <label style="display:block;font-weight:500;margin:.9rem 0 .35rem">Sort code
                    <input type="text" wire:model="sortCode" inputmode="numeric" autocomplete="off"
                           placeholder="12-34-56" data-testid="input-sort-code"
                           style="width:100%;padding:.65rem;border:1px solid #cbd5e1;border-radius:.5rem;margin-top:.3rem">
                </label>
                @error('sortCode')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror

                <label style="display:block;font-weight:500;margin:.9rem 0 .35rem">Account number
                    <input type="text" wire:model="accountNumber" inputmode="numeric" autocomplete="off"
                           placeholder="12345678" data-testid="input-account-number"
                           style="width:100%;padding:.65rem;border:1px solid #cbd5e1;border-radius:.5rem;margin-top:.3rem">
                </label>
                @error('accountNumber')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror
            </div>
        @elseif ($preferredMethod === 'account_credit')
            <div style="margin-top:1.25rem;background:#f8fafc;border-radius:.5rem;padding:1rem;color:#334155;font-size:.95rem"
                 data-testid="payout-credit-fields">
                <strong>Account Credit</strong> — add your reward to your AIO balance to use toward eligible AIO Media purchases or renewals.
                No bank details are required for this option. Spending at checkout is not enabled yet; credited balances are held on your account.
            </div>
        @endif

        @if ($preferredMethod === 'bank_transfer' || ($preferredMethod === 'account_credit' && ($hasSensitiveDestination || $isLegacyPayPal)))
            <div style="margin-top:1.25rem;background:#fef3c7;border-radius:.5rem;padding:1rem" data-testid="payout-password-panel">
                <label style="display:block;font-weight:600;color:#92400e">Confirm your account password
                    <input type="password" wire:model="confirmPassword" autocomplete="current-password"
                           data-testid="input-payout-password"
                           style="width:100%;padding:.65rem;border:1px solid #d97706;border-radius:.35rem;margin-top:.35rem">
                </label>
                @error('confirmPassword')<div style="color:#dc2626;margin-top:.25rem;font-size:.85rem">{{ $message }}</div>@enderror
            </div>
        @endif

        <div style="margin-top:1.25rem">
            <button type="submit" data-testid="btn-save-payout"
                    style="padding:.65rem 1.1rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer">
                Save payout settings
            </button>
        </div>
    </form>
</div>
