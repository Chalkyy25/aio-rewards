<?php

namespace Database\Factories;

use App\Models\RewardMilestoneTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardMilestoneTierFactory extends Factory
{
    protected $model = RewardMilestoneTier::class;

    public function definition(): array
    {
        return [
            'threshold' => 5,
            'total_reward_amount_minor' => 5000,
            'bonus_amount_minor' => 0,
            'currency' => 'gbp',
            'title' => '£50 Reward',
            'description' => 'Cash out at 5 approved referrals.',
            'display_order' => 10,
            'is_active' => true,
            'is_visible' => true,
            'is_claimable' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
