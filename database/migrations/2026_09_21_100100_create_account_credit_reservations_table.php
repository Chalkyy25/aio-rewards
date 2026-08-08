<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft holds of Account Credit pending Stripe success (or full-credit commit).
 *
 * Reservations are NOT ledger debits. Available balance = ledger balance − active reservations.
 * purchase_id is ULID (matches purchases.id) — never foreignId().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_credit_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles');
            $t->ulid('purchase_id');
            $t->foreign('purchase_id')
                ->references('id')
                ->on('purchases')
                ->cascadeOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3)->default('gbp');
            // pending | committed | released | expired
            $t->string('status', 24)->default('pending');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('idempotency_key', 128);
            $t->timestamps();

            $t->unique('idempotency_key', 'acct_credit_res_idempotency_unique');
            $t->unique('purchase_id', 'acct_credit_res_purchase_unique');
            $t->index(['ambassador_profile_id', 'status'], 'acct_credit_res_member_status_idx');
            $t->index(['status', 'expires_at'], 'acct_credit_res_expiry_idx');
        });

        Schema::table('purchases', function (Blueprint $t) {
            $t->unsignedInteger('account_credit_applied_minor')->default(0)->after('amount_minor');
            $t->unsignedInteger('external_amount_minor')->nullable()->after('account_credit_applied_minor');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn(['account_credit_applied_minor', 'external_amount_minor']);
        });

        Schema::dropIfExists('account_credit_reservations');
    }
};
