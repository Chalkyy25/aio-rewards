<?php

namespace Database\Factories;

use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralConversionFactory extends Factory
{
    protected $model = ReferralConversion::class;

    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory()->state(fn () => [
                'package_id' => Package::factory(),
                'status' => 'paid',
                'paid_at' => now(),
            ]),
            'ambassador_profile_id' => AmbassadorProfile::factory(),
            'referral_code_snapshot' => 'TESTCODE',
            'status' => 'pending',
            'amount_minor' => 6000,
            'currency' => 'gbp',
            'pending_until' => now()->addDays(14),
        ];
    }
}
