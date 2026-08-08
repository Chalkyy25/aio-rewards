<?php

namespace App\Domain\Payouts;

use App\Enums\PayoutMethod;
use App\Models\MemberPayoutProfile;
use App\Models\Reward;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Authorised, audited reveal of encrypted payout destination fields.
 *
 * Shared by Ambassador and Reward admin surfaces. Never writes plaintext
 * bank details to the audit log, notifications, or persistent storage.
 */
final class RevealPayoutDetailsService
{
    public function __construct(
        private readonly RevealedPayoutDetailsStore $store,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reveal(
        MemberPayoutProfile $profile,
        User $actor,
        #[SensitiveParameter] string $password,
        string $reason,
        string $source,
        ?Reward $reward = null,
    ): RevealedPayoutDetails {
        if (! Gate::forUser($actor)->allows('reveal', $profile)) {
            throw new AuthorizationException('Not authorised to reveal payout details.');
        }

        if (! $profile->preferred_method->storesSensitiveDestination() || ! $profile->isConfigured()) {
            throw ValidationException::withMessages([
                'reason' => 'This payout profile has no sensitive destination to reveal.',
            ]);
        }

        if ($password === '' || ! Hash::check($password, (string) $actor->password)) {
            throw ValidationException::withMessages([
                'password' => 'Password did not match.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required.',
            ]);
        }

        // Audit safe metadata only — never the revealed values.
        AuditLogger::record(
            action: 'payout_profile.details_revealed',
            subject: $profile,
            actor: $actor,
            context: array_filter([
                'ambassador_profile_id' => $profile->ambassador_profile_id,
                'reward_id' => $reward?->id,
                'method' => $profile->preferred_method->value,
                'reason' => $reason,
                'source' => $source,
            ], fn ($v) => $v !== null),
        );

        $details = match ($profile->preferred_method) {
            PayoutMethod::BankTransfer => new RevealedPayoutDetails(
                method: PayoutMethod::BankTransfer,
                accountHolderName: $profile->account_holder_name,
                sortCode: $profile->sort_code,
                accountNumber: $profile->account_number,
            ),
            PayoutMethod::PayPal => new RevealedPayoutDetails(
                method: PayoutMethod::PayPal,
                accountHolderName: null,
                sortCode: null,
                accountNumber: null,
                paypalEmail: $profile->paypal_email,
            ),
            default => throw ValidationException::withMessages([
                'reason' => 'This payout profile has no sensitive destination to reveal.',
            ]),
        };

        $this->store->put($details);

        return $details;
    }
}
