<?php

namespace App\Livewire;

use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

#[Layout('layouts.ambassador')]
class AmbassadorSecurity extends Component
{
    public bool $mfaEnabled = false;

    public bool $showEnrolment = false;

    public string $enrolmentSecret = '';

    public string $enrolmentQrUri = '';

    public string $enrolmentCode = '';

    /** @var array<int, string> */
    public array $recoveryCodes = [];

    public bool $showRecovery = false;

    public string $confirmPassword = '';

    public string $flash = '';

    public string $flashKind = 'success';

    public function mount(): void
    {
        $user = Auth::user();
        $this->mfaEnabled = (bool) $user->mfa_enabled;
    }

    public function render(): View
    {
        return view('livewire.ambassador-security');
    }

    public function startEnrolment(): void
    {
        if ($this->mfaEnabled) {
            return;
        }
        $g2fa = new Google2FA;
        $this->enrolmentSecret = $g2fa->generateSecretKey();
        $this->enrolmentQrUri = $g2fa->getQRCodeUrl(
            config('app.name'),
            (string) Auth::user()->email,
            $this->enrolmentSecret,
        );
        $this->enrolmentCode = '';
        $this->showEnrolment = true;
    }

    public function confirmEnrolment(): void
    {
        $code = trim($this->enrolmentCode);
        if (! $this->verifyCode($this->enrolmentSecret, $code)) {
            $this->addError('enrolmentCode', 'That code did not match. Try again.');

            return;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $codes = $this->generateRecoveryCodes();

        $user->saveAppAuthenticationSecret($this->enrolmentSecret);
        $user->saveAppAuthenticationRecoveryCodes($codes);
        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_enabled_at' => now(),
        ])->save();

        $this->mfaEnabled = true;
        $this->recoveryCodes = $codes;
        $this->showRecovery = true;
        $this->showEnrolment = false;
        $this->enrolmentSecret = '';
        $this->enrolmentQrUri = '';
        $this->enrolmentCode = '';

        AuditLogger::record('user.mfa_enabled', $user, actor: $user);
        $this->flash = 'Two-factor authentication is now on. Save your recovery codes before leaving this page.';
        $this->flashKind = 'success';
    }

    public function cancelEnrolment(): void
    {
        $this->showEnrolment = false;
        $this->enrolmentSecret = '';
        $this->enrolmentQrUri = '';
        $this->enrolmentCode = '';
    }

    public function disableMfa(#[SensitiveParameter] ?string $password = null): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $submitted = $password ?? $this->confirmPassword;

        if (! Hash::check($submitted, $user->password)) {
            $this->addError('confirmPassword', 'Password did not match.');

            return;
        }

        $user->saveAppAuthenticationSecret(null);
        $user->saveAppAuthenticationRecoveryCodes(null);
        $user->forceFill(['mfa_enabled' => false, 'mfa_enabled_at' => null])->save();

        $this->mfaEnabled = false;
        $this->confirmPassword = '';
        $this->recoveryCodes = [];
        $this->showRecovery = false;

        AuditLogger::record('user.mfa_disabled', $user, actor: $user);
        $this->flash = 'Two-factor authentication has been turned off.';
        $this->flashKind = 'success';
    }

    public function regenerateRecoveryCodes(): void
    {
        if (! $this->mfaEnabled) {
            return;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $codes = $this->generateRecoveryCodes();
        $user->saveAppAuthenticationRecoveryCodes($codes);

        $this->recoveryCodes = $codes;
        $this->showRecovery = true;

        AuditLogger::record('user.mfa_recovery_regenerated', $user, actor: $user);
        $this->flash = 'New recovery codes generated. Save them before leaving the page.';
        $this->flashKind = 'success';
    }

    /** @return array<int, string> */
    private function generateRecoveryCodes(int $count = 8): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = strtolower(Str::random(5)).'-'.strtolower(Str::random(5));
        }

        return $out;
    }

    public function verifyCode(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        return (new Google2FA)->verifyKey($secret, $code, window: 2);
    }
}
