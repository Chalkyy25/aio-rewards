<?php

namespace App\Domain\Payouts;

use App\Enums\PayoutMethod;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Owns create/update/reset of Rewards Member payout preferences.
 *
 * Deliberate field-clearing policy: when the preferred method changes,
 * irrelevant encrypted destination fields are nullified so previously
 * stored bank/PayPal details cannot leak after a switch.
 */
final class MemberPayoutProfileService
{
    /**
     * @param  array{
     *     preferred_method: string|PayoutMethod,
     *     account_holder_name?: ?string,
     *     sort_code?: ?string,
     *     account_number?: ?string,
     *     paypal_email?: ?string,
     * }  $input
     */
    public function save(
        AmbassadorProfile $profile,
        User $actor,
        array $input,
        #[SensitiveParameter] ?string $password = null,
    ): MemberPayoutProfile {
        $method = $input['preferred_method'] instanceof PayoutMethod
            ? $input['preferred_method']
            : PayoutMethod::from((string) $input['preferred_method']);

        $existing = MemberPayoutProfile::query()
            ->where('ambassador_profile_id', $profile->id)
            ->first();

        $requiresPassword = $this->requiresPasswordConfirmation($method, $existing);
        if ($requiresPassword) {
            if ($password === null || $password === ''
                || ! \Illuminate\Support\Facades\Hash::check($password, (string) $actor->password)) {
                throw ValidationException::withMessages([
                    'confirmPassword' => 'Password did not match.',
                ]);
            }
        }

        $payload = $this->normalisedPayload($method, $input);

        return DB::transaction(function () use ($profile, $actor, $existing, $method, $payload) {
            $before = $existing?->auditSafeSnapshot();

            if ($existing) {
                $existing->fill($payload);
                $existing->save();
                $saved = $existing->fresh();
                $action = ($before['method'] ?? null) !== $method->value
                    ? 'payout_profile.method_changed'
                    : 'payout_profile.updated';
            } else {
                $saved = MemberPayoutProfile::query()->create([
                    'ambassador_profile_id' => $profile->id,
                    'user_id' => $profile->user_id,
                    ...$payload,
                ]);
                $action = 'payout_profile.created';
            }

            // Allow a fresh prompt if the member later clears their profile.
            if ($profile->payout_prompt_sent_at !== null) {
                $profile->forceFill(['payout_prompt_sent_at' => null])->save();
            }

            AuditLogger::record(
                action: $action,
                subject: $saved,
                before: $before,
                after: $saved->auditSafeSnapshot(),
                actor: $actor,
                context: ['ambassador_profile_id' => $profile->id],
            );

            return $saved;
        });
    }

    public function reset(AmbassadorProfile $profile, User $actor): void
    {
        $existing = MemberPayoutProfile::query()
            ->where('ambassador_profile_id', $profile->id)
            ->first();

        if (! $existing) {
            return;
        }

        $before = $existing->auditSafeSnapshot();
        $existing->delete();

        AuditLogger::record(
            action: 'payout_profile.removed',
            subject: $profile,
            before: $before,
            actor: $actor,
            context: ['ambassador_profile_id' => $profile->id],
        );
    }

    private function requiresPasswordConfirmation(PayoutMethod $method, ?MemberPayoutProfile $existing): bool
    {
        if ($method->storesSensitiveDestination()) {
            return true;
        }

        // Switching to Account Credit while sensitive destination data exists
        // clears those fields — treat as a sensitive change.
        if ($existing && $existing->preferred_method->storesSensitiveDestination()) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     preferred_method: PayoutMethod,
     *     account_holder_name: ?string,
     *     sort_code: ?string,
     *     account_number: ?string,
     *     paypal_email: ?string,
     * }
     */
    private function normalisedPayload(PayoutMethod $method, array $input): array
    {
        return match ($method) {
            PayoutMethod::BankTransfer => [
                'preferred_method' => $method,
                'account_holder_name' => trim((string) ($input['account_holder_name'] ?? '')),
                'sort_code' => $this->normaliseSortCode((string) ($input['sort_code'] ?? '')),
                'account_number' => preg_replace('/\D+/', '', (string) ($input['account_number'] ?? '')) ?: null,
                'paypal_email' => null,
            ],
            PayoutMethod::PayPal => [
                'preferred_method' => $method,
                'account_holder_name' => null,
                'sort_code' => null,
                'account_number' => null,
                'paypal_email' => strtolower(trim((string) ($input['paypal_email'] ?? ''))),
            ],
            PayoutMethod::AccountCredit => [
                'preferred_method' => $method,
                'account_holder_name' => null,
                'sort_code' => null,
                'account_number' => null,
                'paypal_email' => null,
            ],
        };
    }

    private function normaliseSortCode(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 6) {
            return null;
        }

        return substr($digits, 0, 2).'-'.substr($digits, 2, 2).'-'.substr($digits, 4, 2);
    }
}
