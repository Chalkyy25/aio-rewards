<?php

namespace App\Livewire;

use App\Domain\Payouts\MemberPayoutProfileService;
use App\Enums\PayoutMethod;
use App\Models\MemberPayoutProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use SensitiveParameter;

#[Layout('layouts.ambassador')]
class AmbassadorPayoutSettings extends Component
{
    public string $preferredMethod = '';

    public string $accountHolderName = '';

    public string $sortCode = '';

    public string $accountNumber = '';

    public string $confirmPassword = '';

    public string $flash = '';

    public string $flashKind = 'success';

    public ?int $profileId = null;

    public string $currentMethodLabel = '';

    public string $maskedSortCode = '';

    public string $maskedAccountNumber = '';

    public string $maskedPayPalEmail = '';

    public string $displayAccountHolder = '';

    public string $lastUpdated = '';

    public bool $isConfigured = false;

    public bool $hasSensitiveDestination = false;

    public bool $isLegacyPayPal = false;

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user && $user->ambassadorProfile, 403);
        abort_unless((bool) $user->is_active, 403);

        $this->hydrateFromProfile(
            MemberPayoutProfile::query()
                ->where('ambassador_profile_id', $user->ambassadorProfile->id)
                ->first()
        );
    }

    public function render(): View
    {
        return view('livewire.ambassador-payout-settings');
    }

    public function updatedPreferredMethod(): void
    {
        // Clear form fields that no longer apply so stale values cannot
        // be accidentally re-submitted for the newly selected method.
        if ($this->preferredMethod !== PayoutMethod::BankTransfer->value) {
            $this->accountHolderName = '';
            $this->sortCode = '';
            $this->accountNumber = '';
        }
        $this->resetErrorBag();
    }

    public function save(MemberPayoutProfileService $service, #[SensitiveParameter] ?string $password = null): void
    {
        $user = Auth::user();
        abort_unless($user && $user->ambassadorProfile, 403);

        $this->validate($this->rules());

        try {
            $saved = $service->save(
                profile: $user->ambassadorProfile,
                actor: $user,
                input: [
                    'preferred_method' => $this->preferredMethod,
                    'account_holder_name' => $this->accountHolderName,
                    'sort_code' => $this->sortCode,
                    'account_number' => $this->accountNumber,
                ],
                password: $password ?? $this->confirmPassword,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->confirmPassword = '';
        $this->accountHolderName = '';
        $this->sortCode = '';
        $this->accountNumber = '';
        $this->hydrateFromProfile($saved);
        $this->flash = 'Payout settings saved.';
        $this->flashKind = 'success';
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'preferredMethod' => ['required', Rule::in(array_keys(PayoutMethod::configurableOptions()))],
        ];

        if ($this->preferredMethod === PayoutMethod::BankTransfer->value) {
            $rules['accountHolderName'] = ['required', 'string', 'max:120'];
            $rules['sortCode'] = ['required', 'regex:/^\d{2}-?\d{2}-?\d{2}$/'];
            $rules['accountNumber'] = ['required', 'regex:/^\d{8}$/'];
            $rules['confirmPassword'] = ['required', 'string'];
        } elseif ($this->preferredMethod === PayoutMethod::AccountCredit->value) {
            // Password only required when clearing an existing sensitive destination.
            if ($this->hasSensitiveDestination || $this->isLegacyPayPal) {
                $rules['confirmPassword'] = ['required', 'string'];
            }
        }

        return $rules;
    }

    private function hydrateFromProfile(?MemberPayoutProfile $profile): void
    {
        if (! $profile) {
            $this->profileId = null;
            $this->preferredMethod = '';
            $this->currentMethodLabel = '';
            $this->maskedSortCode = '';
            $this->maskedAccountNumber = '';
            $this->maskedPayPalEmail = '';
            $this->displayAccountHolder = '';
            $this->lastUpdated = '';
            $this->isConfigured = false;
            $this->hasSensitiveDestination = false;
            $this->isLegacyPayPal = false;

            return;
        }

        $this->profileId = $profile->id;
        $this->preferredMethod = $profile->preferred_method->isConfigurable()
            ? $profile->preferred_method->value
            : '';
        $this->currentMethodLabel = $profile->preferred_method->label();
        $this->isConfigured = $profile->isConfigured();
        $this->hasSensitiveDestination = $profile->preferred_method->storesSensitiveDestination();
        $this->isLegacyPayPal = $profile->preferred_method === PayoutMethod::PayPal;
        $this->lastUpdated = optional($profile->updated_at)?->timezone(config('app.timezone'))->toDayDateTimeString() ?? '';
        $this->maskedSortCode = (string) ($profile->maskedSortCode() ?? '');
        $this->maskedAccountNumber = (string) ($profile->maskedAccountNumber() ?? '');
        $this->maskedPayPalEmail = (string) ($profile->maskedPayPalEmail() ?? '');
        $this->displayAccountHolder = (string) ($profile->account_holder_name ?? '');
    }
}
