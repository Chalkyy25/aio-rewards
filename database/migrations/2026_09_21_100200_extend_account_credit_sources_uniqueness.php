<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure at most one purchase_redemption (and one credit_restoration) ledger
 * row per purchase. reward_id+source uniqueness already covers base+bonus.
 *
 * purchase_id remains ULID — do not alter column type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_credit_transactions', function (Blueprint $t) {
            // MySQL allows multiple NULLs in a unique index, so rows without a
            // purchase_id are unaffected. Debit/restore sources are constrained.
            $t->unique(['purchase_id', 'source'], 'acct_credit_purchase_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('account_credit_transactions', function (Blueprint $t) {
            $t->dropUnique('acct_credit_purchase_source_unique');
        });
    }
};
