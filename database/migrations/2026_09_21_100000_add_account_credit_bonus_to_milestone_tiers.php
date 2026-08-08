<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tier Account Credit promotional bonus (additive on top of cash reward
 * when the member chooses Account Credit fulfilment).
 *
 * Distinct from `bonus_amount_minor` (Save & Grow display figure baked into
 * the cash total narrative).
 *
 * Snapshot strategy for existing rewards: default snapshot to 0 so historical
 * unpaid rewards do not silently gain a new financial obligation.
 * New claims snapshot the tier's configured bonus at claim time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_milestone_tiers', function (Blueprint $t) {
            $t->unsignedInteger('account_credit_bonus_minor')
                ->default(0)
                ->after('bonus_amount_minor');
        });

        // Product-sensible defaults for the seeded ladder. Admin may change
        // any tier independently afterward (including setting bonus to £0).
        $defaults = [
            5 => 1000,   // £50 cash + £10 AC bonus → £60 AC
            10 => 2000,  // £110 cash + £20 AC bonus → £130 AC
            15 => 3000,  // £170 cash + £30 AC bonus → £200 AC
            20 => 4000,  // £235 cash + £40 AC bonus → £275 AC
        ];

        foreach ($defaults as $threshold => $bonus) {
            DB::table('reward_milestone_tiers')
                ->where('threshold', $threshold)
                ->update([
                    'account_credit_bonus_minor' => $bonus,
                    'updated_at' => now(),
                ]);
        }

        Schema::table('rewards', function (Blueprint $t) {
            $t->unsignedInteger('account_credit_bonus_minor_snapshot')
                ->default(0)
                ->after('amount_minor');
        });

        // Existing rewards: keep snapshot at 0 (column default). Do not backfill
        // current tier bonuses — that would silently change financial obligations.
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->dropColumn('account_credit_bonus_minor_snapshot');
        });

        Schema::table('reward_milestone_tiers', function (Blueprint $t) {
            $t->dropColumn('account_credit_bonus_minor');
        });
    }
};
