<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The new data-driven Reward Milestone ladder is the sole engine for
 * new member milestone claims. Deactivate the legacy seeded
 * `Every 5 approved referrals = £50` rule so it stops minting rewards
 * alongside the ladder. Historical Reward rows created by the old rule
 * are preserved untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('reward_rules')
            ->where('name', 'Every 5 approved referrals = £50')
            ->where('kind', 'every_n_cash')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('reward_rules')
            ->where('name', 'Every 5 approved referrals = £50')
            ->where('kind', 'every_n_cash')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
