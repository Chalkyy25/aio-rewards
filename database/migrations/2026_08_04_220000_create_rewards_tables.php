<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_rules', function (Blueprint $t) {
            $t->id();
            $t->string('name', 190);
            // 'every_n_cash' now; 'percentage' + 'lifetime_revenue' reserved for later phases.
            $t->string('kind', 32)->default('every_n_cash');
            $t->unsignedInteger('trigger_count')->default(1);
            $t->unsignedInteger('amount_minor')->default(0);
            $t->char('currency', 3)->default('gbp');
            // For future percentage rewards (basis points).
            $t->unsignedInteger('percentage_bps')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles');
            $t->foreignId('reward_rule_id')->nullable()->constrained('reward_rules')->nullOnDelete();
            $t->foreignId('trigger_conversion_id')->nullable()->constrained('referral_conversions')->nullOnDelete();
            $t->unsignedInteger('milestone_index')->default(1);
            $t->unsignedInteger('amount_minor');
            $t->char('currency', 3)->default('gbp');
            $t->string('status', 24)->default('pending_approval'); // pending_approval|approved|rejected|paid|reversed
            $t->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();

            // Guarantee one reward per rule + ambassador + milestone.
            $t->unique(['ambassador_profile_id', 'reward_rule_id', 'milestone_index'], 'rewards_unique_milestone');
            $t->index(['status', 'ambassador_profile_id']);
        });

        // Seed the default rule requested by the product spec.
        DB::table('reward_rules')->insert([
            'name' => 'Every 5 approved referrals = £50',
            'kind' => 'every_n_cash',
            'trigger_count' => 5,
            'amount_minor' => 5000,
            'currency' => 'gbp',
            'is_active' => true,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('reward_rules');
    }
};
