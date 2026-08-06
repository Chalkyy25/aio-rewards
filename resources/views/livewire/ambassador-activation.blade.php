@push('head')
<style>
    .card { max-width: 640px; margin: 2rem auto; background: #fff; border-radius: 1rem;
            padding: 2.5rem; box-shadow: 0 2px 30px -12px rgba(15,23,42,.15); }
    fieldset { border: 0; padding: 0; margin: 0 0 1.5rem; }
    legend { font-weight: 700; color: #0f172a; font-size: 1rem; margin-bottom: .5rem;
             letter-spacing: .01em; }
    .field { margin-bottom: .9rem; }
    label { display: block; margin-bottom: .3rem; font-weight: 500; }
    input[type=text], input[type=email], input[type=password] {
        width: 100%; padding: .7rem .75rem; border: 1px solid #cbd5e1; border-radius: .5rem;
        font-size: 1rem; box-sizing: border-box; }
    input:focus { outline: 2px solid #0f172a; outline-offset: 1px; }
    small.help { color: #64748b; display: block; margin-top: .25rem; }
    .field-error { color: #991b1b; font-size: .875rem; margin-top: .25rem; }
    button.primary { width: 100%; padding: .95rem 1rem; background: #0f172a; color: #fff;
                     border: 0; border-radius: .5rem; font-weight: 600; margin-top: 1rem;
                     cursor: pointer; font-size: 1rem; }
    button.primary:disabled { opacity: .6; cursor: not-allowed; }
    .alert-error { background: #fef2f2; color: #991b1b; padding: .8rem 1rem;
                   border-radius: .5rem; margin-bottom: 1rem; }
    .consent { display: flex; align-items: flex-start; gap: .5rem; margin-top: .5rem; }
    .consent input { margin-top: .3rem; }
    hr { border: 0; border-top: 1px solid #e2e8f0; margin: 1.5rem 0; }
</style>
@endpush

<div class="card">
    <h1 style="margin:0 0 .5rem;font-size:1.5rem">Join AIO Rewards</h1>
    <p style="color:#475569;margin:0 0 1.5rem">
        Confirm you're an active AIO Media customer, then choose your new AIO Rewards login.
    </p>

    @if ($errorMessage)
        <div class="alert-error" data-testid="activate-error-banner">{{ $errorMessage }}</div>
    @endif

    <form wire:submit="submit" data-testid="activate-form">
        <fieldset>
            <legend>Verify your existing subscription</legend>

            <div class="field">
                <label for="provider_username">Your existing AIO Media username</label>
                <input id="provider_username" data-testid="activate-provider-username"
                       type="text" wire:model="provider_username" autocomplete="username"
                       required>
                @error('provider_username')
                    <div class="field-error" data-testid="err-provider-username">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="provider_password">Your existing AIO Media password</label>
                <input id="provider_password" data-testid="activate-provider-password"
                       type="password" wire:model="provider_password" autocomplete="current-password"
                       required>
                <small class="help">
                    This password is used only to verify your subscription is active. It is
                    never stored, logged, or shared. Learn more in our privacy notice.
                </small>
                @error('provider_password')
                    <div class="field-error" data-testid="err-provider-password">{{ $message }}</div>
                @enderror
            </div>
        </fieldset>

        <hr>

        <fieldset>
            <legend>Create your AIO Rewards account</legend>

            <div class="field">
                <label for="name">Full name</label>
                <input id="name" data-testid="activate-name" type="text"
                       wire:model="name" autocomplete="name" required>
                @error('name')
                    <div class="field-error" data-testid="err-name">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input id="email" data-testid="activate-email" type="email"
                       wire:model="email" autocomplete="email" required>
                @error('email')
                    <div class="field-error" data-testid="err-email">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password (min 12 characters)</label>
                <input id="password" data-testid="activate-password" type="password"
                       wire:model="password" autocomplete="new-password" required>
                @error('password')
                    <div class="field-error" data-testid="err-password">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" data-testid="activate-password-confirmation"
                       type="password" wire:model="password_confirmation"
                       autocomplete="new-password" required>
            </div>
        </fieldset>

        <label class="consent">
            <input type="checkbox" wire:model="consent" data-testid="activate-consent" required>
            <span>
                I confirm that I own this AIO Media subscription and I agree to the
                AIO Rewards programme terms.
            </span>
        </label>
        @error('consent')
            <div class="field-error" data-testid="err-consent">{{ $message }}</div>
        @enderror

        <button class="primary" type="submit" data-testid="activate-submit"
                wire:loading.attr="disabled" wire:target="submit">
            <span wire:loading.remove wire:target="submit">Join AIO Rewards</span>
            <span wire:loading wire:target="submit">Verifying…</span>
        </button>
    </form>

    <p style="text-align:center;margin-top:1rem;color:#64748b;font-size:.9rem">
        Already a Rewards Member? <a href="{{ route('login') }}" style="color:#0f172a">Sign in</a>.
    </p>
</div>
