<?php

namespace Database\Factories;

use App\Models\RewardRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardRuleFactory extends Factory
{
    protected $model = RewardRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Every 5 = £50',
            'kind' => 'every_n_cash',
            'trigger_count' => 5,
            'amount_minor' => 5000,
            'currency' => 'gbp',
            'is_active' => true,
            'sort_order' => 10,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
