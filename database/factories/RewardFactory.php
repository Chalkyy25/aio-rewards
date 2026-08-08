<?php

namespace Database\Factories;

use App\Models\AmbassadorProfile;
use App\Models\Reward;
use App\Models\RewardRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardFactory extends Factory
{
    protected $model = Reward::class;

    public function definition(): array
    {
        return [
            'ambassador_profile_id' => AmbassadorProfile::factory(),
            'reward_rule_id' => RewardRule::factory(),
            'milestone_index' => 1,
            'amount_minor' => 5000,
            'account_credit_bonus_minor_snapshot' => 0,
            'currency' => 'gbp',
            'status' => 'pending_approval',
        ];
    }
}
