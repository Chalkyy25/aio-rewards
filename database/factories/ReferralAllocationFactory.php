<?php

namespace Database\Factories;

use App\Models\AmbassadorProfile;
use App\Models\ReferralAllocation;
use App\Models\ReferralConversion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralAllocationFactory extends Factory
{
    protected $model = ReferralAllocation::class;

    public function definition(): array
    {
        return [
            'referral_conversion_id' => ReferralConversion::factory(),
            'ambassador_profile_id' => AmbassadorProfile::factory(),
            'cycle_number' => 1,
            'reward_id' => null,
            'active_marker' => 1,
            'allocated_at' => now(),
        ];
    }
}
