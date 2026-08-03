<?php

namespace Database\Factories;

use App\Models\AmbassadorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmbassadorProfile>
 */
class AmbassadorProfileFactory extends Factory
{
    protected $model = AmbassadorProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_username' => $this->faker->unique()->userName(),
            'provider_customer_ref' => 'ref-'.$this->faker->unique()->uuid(),
            'provider_driver_key' => 'fake',
            'referral_code' => strtoupper($this->faker->unique()->bothify('????####')),
            'activated_at' => now(),
        ];
    }
}
