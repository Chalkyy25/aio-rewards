<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            // Provisioned credentials (encrypted at rest via Eloquent 'encrypted' cast).
            $t->text('provisioned_username_enc')->nullable()->after('fulfilled_by_user_id');
            $t->text('provisioned_password_enc')->nullable()->after('provisioned_username_enc');
            $t->date('provisioned_expires_on')->nullable()->after('provisioned_password_enc');

            // Fulfilment content shared with the buyer on the status page.
            $t->text('setup_instructions_md')->nullable()->after('provisioned_expires_on');
            $t->json('download_links')->nullable()->after('setup_instructions_md');
            $t->text('fulfilment_notes')->nullable()->after('download_links');

            // Public status page token (opaque, 32 chars).
            $t->string('customer_view_token', 64)->nullable()->unique()->after('fulfilment_notes');

            // Timeline stamps.
            $t->timestamp('payment_received_at')->nullable()->after('paid_at');
            $t->timestamp('setup_started_at')->nullable()->after('payment_received_at');
            $t->timestamp('awaiting_customer_at')->nullable()->after('setup_started_at');
            $t->timestamp('completed_at')->nullable()->after('awaiting_customer_at');
            $t->timestamp('cancelled_at')->nullable()->after('completed_at');
            $t->timestamp('refunded_at')->nullable()->after('cancelled_at');
        });

        Schema::create('referral_conversions', function (Blueprint $t) {
            $t->id();
            $t->ulid('purchase_id');
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles');
            $t->string('referral_code_snapshot', 32);
            $t->string('status', 16)->default('pending'); // pending|approved|reversed
            $t->unsignedInteger('amount_minor');
            $t->char('currency', 3)->default('gbp');
            $t->timestamp('pending_until')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reversed_reason', 64)->nullable(); // refund|chargeback|admin
            $t->timestamps();

            $t->foreign('purchase_id')->references('id')->on('purchases')->cascadeOnDelete();
            $t->unique('purchase_id');
            $t->index(['ambassador_profile_id', 'status']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_conversions');

        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn([
                'provisioned_username_enc',
                'provisioned_password_enc',
                'provisioned_expires_on',
                'setup_instructions_md',
                'download_links',
                'fulfilment_notes',
                'customer_view_token',
                'payment_received_at',
                'setup_started_at',
                'awaiting_customer_at',
                'completed_at',
                'cancelled_at',
                'refunded_at',
            ]);
        });
    }
};
