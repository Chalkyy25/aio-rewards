<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $t) {
            $t->id();
            $t->string('name', 190);
            $t->string('slug', 190)->unique();
            $t->string('short_description', 500);
            $t->text('full_description')->nullable();
            $t->string('stripe_price_id', 191)->nullable();
            $t->unsignedInteger('amount_minor');
            $t->char('currency', 3)->default('gbp');
            $t->string('duration_label', 64);
            $t->boolean('includes_vpn')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('purchases', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('package_id')->constrained('packages');
            $t->string('buyer_name', 190);
            $t->string('buyer_email', 190);
            $t->string('preferred_username', 64);
            $t->string('buyer_phone', 32)->nullable();
            $t->string('buyer_telegram', 64)->nullable();
            $t->string('delivery_method', 16); // whatsapp|email|telegram
            $t->unsignedInteger('amount_minor');
            $t->char('currency', 3)->default('gbp');
            $t->string('status', 16)->default('pending');
            $t->string('fulfilment_status', 16)->default('unfulfilled');
            $t->string('stripe_session_id', 191)->nullable()->unique();
            $t->string('stripe_payment_intent_id', 191)->nullable();
            $t->string('stripe_charge_id', 191)->nullable();
            $t->string('attribution_id', 26)->nullable();
            $t->string('referral_code_snapshot', 32)->nullable();
            $t->unsignedBigInteger('ambassador_profile_id_snapshot')->nullable();
            $t->timestamp('terms_accepted_at')->nullable();
            $t->timestamp('privacy_accepted_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->foreignId('fulfilled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['status', 'fulfilment_status']);
            $t->index('attribution_id');
            $t->index('ambassador_profile_id_snapshot');
            $t->index('buyer_email');
        });

        Schema::create('stripe_events', function (Blueprint $t) {
            $t->id();
            $t->string('stripe_event_id', 191)->unique();
            $t->string('type', 128);
            $t->boolean('livemode')->default(false);
            $t->json('payload');
            $t->boolean('signature_verified')->default(false);
            $t->timestamp('processed_at')->nullable();
            $t->text('processing_error')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index('type');
            $t->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('packages');
    }
};
