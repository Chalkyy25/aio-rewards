<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable Account Credit ledger + cached balances.
 *
 * The ledger is authoritative. Balances are a denormalised cache updated
 * in the same transaction as each ledger insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_credit_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles');
            // Signed minor units: positive = credit, negative = debit.
            $t->bigInteger('amount_minor');
            $t->char('currency', 3)->default('gbp');
            // credit | debit
            $t->string('direction', 16);
            // reward_fulfilment | purchase_redemption | admin_adjustment | reversal
            $t->string('source', 48);
            $t->foreignId('reward_id')->nullable()->constrained('rewards')->nullOnDelete();
            $t->ulid('purchase_id')->nullable();
            $t->foreign('purchase_id')
                ->references('id')
                ->on('purchases')
                ->nullOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // system | admin | member
            $t->string('origin', 24)->default('system');
            $t->string('idempotency_key', 128);
            $t->string('reference', 190)->nullable();
            $t->text('note')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique('idempotency_key', 'acct_credit_idempotency_unique');
            // Structural: at most one ledger row per reward+source
            // (e.g. one reward_fulfilment credit). Debits use other sources.
            $t->unique(['reward_id', 'source'], 'acct_credit_reward_source_unique');
            $t->index(['ambassador_profile_id', 'created_at'], 'acct_credit_member_created_idx');
            $t->index(['purchase_id'], 'acct_credit_purchase_idx');
        });

        Schema::create('account_credit_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles');
            $t->bigInteger('balance_minor')->default(0);
            $t->char('currency', 3)->default('gbp');
            $t->timestamps();

            $t->unique('ambassador_profile_id', 'acct_credit_balance_member_unique');
        });

        Schema::table('rewards', function (Blueprint $t) {
            $t->timestamp('funding_compromised_at')->nullable()->after('payment_reference');
            $t->string('funding_compromise_reason', 190)->nullable()->after('funding_compromised_at');
            $t->unsignedBigInteger('funding_compromise_conversion_id')->nullable()->after('funding_compromise_reason');
            $t->foreignId('account_credit_transaction_id')->nullable()->after('funding_compromise_conversion_id')
                ->constrained('account_credit_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $t) {
            $t->dropForeign(['account_credit_transaction_id']);
            $t->dropColumn([
                'funding_compromised_at',
                'funding_compromise_reason',
                'funding_compromise_conversion_id',
                'account_credit_transaction_id',
            ]);
        });
        Schema::dropIfExists('account_credit_balances');
        Schema::dropIfExists('account_credit_transactions');
    }
};
