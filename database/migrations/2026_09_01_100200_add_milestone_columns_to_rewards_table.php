<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->foreignId('milestone_tier_id')->nullable()->after('reward_rule_id')
                ->constrained('reward_milestone_tiers')->nullOnDelete();
            $t->unsignedInteger('cycle_number')->nullable()->after('milestone_index');
            $t->string('origin', 32)->default('legacy_rule')->after('cycle_number');
            $t->json('tier_snapshot')->nullable()->after('origin');
            $t->string('idempotency_key', 128)->nullable()->after('tier_snapshot');
            $t->string('reject_disposition', 32)->nullable()->after('rejected_at');
        });

        // Historical rewards already in the table originate from the legacy engine.
        DB::table('rewards')->whereNull('origin')->update(['origin' => 'legacy_rule']);

        Schema::table('rewards', function (Blueprint $t) {
            $t->unique('idempotency_key', 'rewards_idempotency_unique');
            $t->unique(['ambassador_profile_id', 'milestone_tier_id', 'cycle_number'], 'rewards_tier_cycle_unique');
            $t->index(['origin']);
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->dropUnique('rewards_idempotency_unique');
            $t->dropUnique('rewards_tier_cycle_unique');
            $t->dropIndex(['origin']);
            $t->dropForeign(['milestone_tier_id']);
            $t->dropColumn([
                'milestone_tier_id', 'cycle_number', 'origin', 'tier_snapshot',
                'idempotency_key', 'reject_disposition',
            ]);
        });
    }
};
