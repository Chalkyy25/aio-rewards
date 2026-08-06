<?php

namespace App\Domain\Ambassadors\Services;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Events\AmbassadorActivated;
use App\Domain\Ambassadors\Exceptions\ActivationException;
use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;
use App\Domain\Provider\Exceptions\ProviderUnavailableException;
use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Orchestrates ambassador activation:
 *   1. Uniqueness checks (email + provider username, case-insensitive)
 *   2. Provider verification (username + password)
 *   3. Create User (ambassador role, hashed *new* password) and
 *      AmbassadorProfile (referral code) in one transaction
 *   4. Fire framework Registered event so Laravel emails a verify link
 *   5. Dispatch AmbassadorActivated event
 *   6. Send welcome email with referral link
 *
 * Provider password is passed with #[SensitiveParameter] and is used solely
 * for the outbound verification call. It is never persisted, cached, queued,
 * logged, or written to the audit log.
 */
class AmbassadorActivationService
{
    public function __construct(
        private readonly CustomerVerificationContract $verifier,
        private readonly ReferralCodeGenerator $codes,
    ) {}

    public function activate(ActivationInput $input): AmbassadorProfile
    {
        $email = strtolower(trim($input->email));
        $providerUsername = trim($input->providerUsername);

        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ActivationException::emailAlreadyRegistered();
        }

        if (AmbassadorProfile::whereRaw('LOWER(provider_username) = ?', [strtolower($providerUsername)])->exists()) {
            throw ActivationException::usernameAlreadyActivated();
        }

        $result = $this->verifyOrThrow($providerUsername, $input->providerPassword, $email);

        $ambassador = DB::transaction(function () use ($input, $providerUsername, $email, $result): AmbassadorProfile {
            $user = User::create([
                'name' => trim($input->name),
                'email' => $email,
                'password' => Hash::make($input->newPassword),
                'is_active' => true,
            ]);

            $user->assignRole(RoleEnum::Ambassador->value);

            /** @var AmbassadorProfile $profile */
            $profile = AmbassadorProfile::create([
                'user_id' => $user->id,
                'provider_username' => $providerUsername,
                'provider_customer_ref' => $result->providerCustomerRef,
                'provider_driver_key' => $this->verifier->driverKey(),
                'referral_code' => $this->codes->unique(),
                'activated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'ambassador.activated',
                subject: $profile,
                after: [
                    // NB: no password, no provider password
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'provider_username' => $providerUsername,
                    'provider_driver_key' => $this->verifier->driverKey(),
                    'referral_code' => $profile->referral_code,
                ],
                actor: $user,
            );

            return $profile;
        });

        Event::dispatch(new Registered($ambassador->user));
        Event::dispatch(new AmbassadorActivated($ambassador));

        // Welcome email is NOT sent here. It fires via
        // SendAmbassadorWelcomeAfterVerified once Laravel's Verified event
        // is emitted — i.e. only after the ambassador confirms their email.

        return $ambassador;
    }

    private function verifyOrThrow(
        string $providerUsername,
        #[SensitiveParameter] string $providerPassword,
        ?string $email,
    ): VerifyCustomerResult {
        try {
            $result = $this->verifier->verifyActiveCustomer(new VerifyCustomerRequest(
                providerUsername: $providerUsername,
                providerPassword: $providerPassword,
                email: $email,
            ));
        } catch (ProviderUnavailableException) {
            throw ActivationException::providerUnavailable();
        }

        if (! $result->eligible) {
            throw ActivationException::providerRejected($result->reason);
        }

        return $result;
    }
}
