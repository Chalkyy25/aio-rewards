<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable Stripe payment attempts bound to exact pricing terms.
 *
 * Webhooks reconcile against the attempt row for that stripe_session_id,
 * never against mutable purchase columns alone.
 *
 * Also tracks cumulative external refund / AC restoration for amount-aware
 * mixed-payment refunds, and relaxes (purchase_id, source) uniqueness so
 * incremental credit restorations can be posted safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payment_attempts', function (Blueprint $t) {
            $t->id();
            $t->ulid('purchase_id');
            $t->foreign('purchase_id')
                ->references('id')
                ->on('purchases')
                ->cascadeOnDelete();
            $t->string('stripe_session_id', 191)->nullable();
            $t->string('cancel_token', 64);
            // Immutable pricing snapshot for this attempt.
            $t->unsignedInteger('package_amount_minor');
            $t->unsignedInteger('account_credit_applied_minor')->default(0);
            $t->unsignedInteger('external_amount_minor');
            $t->char('currency', 3)->default('gbp');
            // open | completed | superseded | expired | cancelled
            $t->string('status', 24)->default('open');
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->timestamps();

            $t->unique('cancel_token', 'ppa_cancel_token_unique');
            $t->unique('stripe_session_id', 'ppa_stripe_session_unique');
            $t->index(['purchase_id', 'status'], 'ppa_purchase_status_idx');
        });

        Schema::table('purchases', function (Blueprint $t) {
            $t->unsignedInteger('external_refunded_minor')->default(0)->after('external_amount_minor');
            $t->unsignedInteger('account_credit_restored_minor')->default(0)->after('external_refunded_minor');
            $t->unsignedBigInteger('active_payment_attempt_id')->nullable()->after('stripe_session_id');
        });

        // Allow multiple restoration ledger rows per purchase (cumulative deltas).
        // Redemption uniqueness remains via idempotency_key purchase_redemption:{id}.
        Schema::table('account_credit_transactions', function (Blueprint $t) {
            $t->dropUnique('acct_credit_purchase_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('account_credit_transactions', function (Blueprint $t) {
            $t->unique(['purchase_id', 'source'], 'acct_credit_purchase_source_unique');
        });

        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn([
                'external_refunded_minor',
                'account_credit_restored_minor',
                'active_payment_attempt_id',
            ]);
        });

        Schema::dropIfExists('purchase_payment_attempts');
    }
};
