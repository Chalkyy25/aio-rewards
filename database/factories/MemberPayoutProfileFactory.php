<?php

namespace Database\Factories;

use App\Enums\PayoutMethod;
use App\Models\AmbassadorProfile;
use App\Models\MemberPayoutProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberPayoutProfile>
 */
class MemberPayoutProfileFactory extends Factory
{
    protected $model = MemberPayoutProfile::class;

    public function definition(): array
    {
        $profile = AmbassadorProfile::factory();

        return [
            'ambassador_profile_id' => $profile,
            'user_id' => function (array $attributes) {
                return AmbassadorProfile::query()
                    ->findOrFail($attributes['ambassador_profile_id'])
                    ->user_id;
            },
            'preferred_method' => PayoutMethod::AccountCredit,
            'account_holder_name' => null,
            'sort_code' => null,
            'account_number' => null,
            'paypal_email' => null,
            'verified_at' => null,
        ];
    }

    public function forProfile(AmbassadorProfile $profile): static
    {
        return $this->state(fn () => [
            'ambassador_profile_id' => $profile->id,
            'user_id' => $profile->user_id,
        ]);
    }

    public function bankTransfer(
        string $holder = 'Alex Example',
        string $sortCode = '12-34-56',
        string $accountNumber = '12345678',
    ): static {
        return $this->state(fn () => [
            'preferred_method' => PayoutMethod::BankTransfer,
            'account_holder_name' => $holder,
            'sort_code' => $sortCode,
            'account_number' => $accountNumber,
            'paypal_email' => null,
        ]);
    }

    public function paypal(string $email = 'member@example.com'): static
    {
        return $this->state(fn () => [
            'preferred_method' => PayoutMethod::PayPal,
            'paypal_email' => $email,
            'account_holder_name' => null,
            'sort_code' => null,
            'account_number' => null,
        ]);
    }

    public function accountCredit(): static
    {
        return $this->state(fn () => [
            'preferred_method' => PayoutMethod::AccountCredit,
            'account_holder_name' => null,
            'sort_code' => null,
            'account_number' => null,
            'paypal_email' => null,
        ]);
    }
}
