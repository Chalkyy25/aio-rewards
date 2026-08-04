<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'short_description' => $this->faker->sentence(),
            'full_description' => null,
            'stripe_price_id' => null,
            'amount_minor' => 6000,
            'currency' => 'gbp',
            'duration_label' => '12 months',
            'includes_vpn' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
