<?php

namespace App\Livewire;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Exceptions\ActivationException;
use App\Domain\Ambassadors\Services\AmbassadorActivationService;
use App\Models\AmbassadorProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Public ambassador activation form.
 *
 * The provider password is never persisted on the component: it's held in
 * an in-memory property purely for the duration of the submit and is set to
 * an empty string in `finally` so it cannot leak into next request's state.
 */
class AmbassadorActivation extends Component
{
    #[Validate('required|string|min:2|max:255')]
    public string $name = '';

    #[Validate('required|email:rfc|max:255')]
    public string $email = '';

    #[Validate('required|string|min:12|max:255|confirmed')]
    public string $password = '';

    #[Validate('required|string|min:12|max:255')]
    public string $password_confirmation = '';

    #[Validate('required|string|min:2|max:190')]
    public string $provider_username = '';

    /**
     * Provider password is intentionally not annotated with #[Validate] to
     * keep the Livewire property from being echoed anywhere. Manual validation
     * is applied on submit.
     */
    public string $provider_password = '';

    #[Validate('accepted')]
    public bool $consent = false;

    public ?string $errorMessage = null;

    public function render(): View
    {
        return view('livewire.ambassador-activation')
            ->extends('layouts.public', ['title' => 'Activate your Ambassador account']);
    }

    public function submit(AmbassadorActivationService $service): void
    {
        $this->errorMessage = null;

        $data = $this->validate();

        if (strlen(trim($this->provider_password)) < 1) {
            $this->addError('provider_password', 'Your provider password is required.');

            return;
        }

        $throttleKey = 'activate|'.strtolower($this->provider_username).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->errorMessage = 'Too many activation attempts. Please try again in a few minutes.';

            return;
        }

        try {
            $ambassador = $this->runActivation($service);
        } catch (ActivationException $e) {
            RateLimiter::hit($throttleKey, 60);

            match ($e->reasonKey) {
                'email_already_registered' => $this->addError('email', $e->publicMessage),
                'username_already_activated' => $this->addError('provider_username', $e->publicMessage),
                default => $this->errorMessage = $e->publicMessage,
            };

            return;
        } finally {
            $this->provider_password = '';
        }

        RateLimiter::clear($throttleKey);

        // Sign the new ambassador in and send them to the dashboard.
        Auth::login($ambassador->user, remember: false);
        $this->redirectRoute('ambassador.dashboard', navigate: false);
    }

    private function runActivation(AmbassadorActivationService $service): AmbassadorProfile
    {
        return $service->activate(new ActivationInput(
            providerUsername: trim($this->provider_username),
            providerPassword: $this->provider_password,
            email: trim($this->email),
            name: trim($this->name),
            newPassword: $this->password,
        ));
    }
}
