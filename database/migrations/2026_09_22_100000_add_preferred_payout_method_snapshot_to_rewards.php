<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the member's chosen payout method onto each Reward at claim time.
 *
 * Historical rows remain null — do not backfill from the live preference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->string('preferred_payout_method_snapshot', 32)
                ->nullable()
                ->after('account_credit_bonus_minor_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->dropColumn('preferred_payout_method_snapshot');
        });
    }
};
