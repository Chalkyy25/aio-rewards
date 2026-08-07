<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-driven Reward Milestone Ladder configuration. Replaces the
 * hard-coded seeded `every_n_cash` rule for new claims while leaving
 * the historical `reward_rules`/`rewards` schema intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_milestone_tiers', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('threshold');                    // e.g. 5, 10
            $t->unsignedInteger('total_reward_amount_minor');    // e.g. 5000 / 11000
            $t->unsignedInteger('bonus_amount_minor')->default(0);// display-only bonus figure
            $t->char('currency', 3)->default('gbp');
            $t->string('title', 190);                             // "£50 Reward"
            $t->text('description')->nullable();
            $t->unsignedInteger('display_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_visible')->default(true);
            $t->boolean('is_claimable')->default(true);
            $t->timestamps();

            $t->unique(['threshold', 'is_active'], 'tiers_threshold_unique_active');
            $t->index(['is_active', 'is_visible', 'display_order']);
        });

        DB::table('reward_milestone_tiers')->insert([
            [
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
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'threshold' => 10,
                'total_reward_amount_minor' => 11000,
                'bonus_amount_minor' => 1000,
                'currency' => 'gbp',
                'title' => '£110 Reward',
                'description' => 'Reach 10 approved referrals without cashing out for a £10 Save & Grow bonus.',
                'display_order' => 20,
                'is_active' => true,
                'is_visible' => true,
                'is_claimable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_milestone_tiers');
    }
};
