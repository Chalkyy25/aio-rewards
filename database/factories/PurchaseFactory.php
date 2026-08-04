<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'buyer_name' => $this->faker->name(),
            'buyer_email' => $this->faker->unique()->safeEmail(),
            'preferred_username' => strtolower($this->faker->unique()->userName()),
            'buyer_phone' => null,
            'buyer_telegram' => null,
            'delivery_method' => 'email',
            'amount_minor' => 6000,
            'currency' => 'gbp',
            'status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(['status' => 'paid', 'paid_at' => now()]);
    }
}
