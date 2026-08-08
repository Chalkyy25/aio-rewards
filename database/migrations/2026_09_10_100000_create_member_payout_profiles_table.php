<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_payout_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ambassador_profile_id')->constrained('ambassador_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('preferred_method', 32);
            // Sensitive financial identifiers — encrypted via Eloquent casts.
            $table->text('account_holder_name')->nullable();
            $table->text('sort_code')->nullable();
            $table->text('account_number')->nullable();
            $table->text('paypal_email')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique('ambassador_profile_id');
            $table->unique('user_id');
        });

        Schema::table('ambassador_profiles', function (Blueprint $table) {
            // Idempotency marker for "add payout details" prompts.
            $table->timestamp('payout_prompt_sent_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_profiles', function (Blueprint $table) {
            $table->dropColumn('payout_prompt_sent_at');
        });

        Schema::dropIfExists('member_payout_profiles');
    }
};
