<?php

namespace Database\Factories;

use App\Models\AmbassadorProfile;
use App\Models\ReferralClick;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReferralClick>
 */
class ReferralClickFactory extends Factory
{
    protected $model = ReferralClick::class;

    public function definition(): array
    {
        return [
            'ambassador_profile_id' => AmbassadorProfile::factory(),
            'referral_code_snapshot' => strtoupper(Str::random(8)),
            'attribution_id' => (string) Str::ulid(),
            'ip_hash' => hash('sha256', $this->faker->ipv4()),
            'user_agent' => $this->faker->userAgent(),
            'referer_url' => $this->faker->url(),
            'utm_source' => $this->faker->randomElement([null, 'twitter', 'whatsapp', 'facebook']),
            'utm_medium' => $this->faker->randomElement([null, 'social', 'email']),
            'utm_campaign' => null,
            'is_bot' => false,
            'created_at' => now(),
        ];
    }

    public function bot(): static
    {
        return $this->state(['is_bot' => true, 'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
    }
}
