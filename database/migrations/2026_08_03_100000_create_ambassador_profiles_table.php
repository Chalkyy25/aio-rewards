<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Case-insensitive uniqueness enforced at both DB and service layer.
            $table->string('provider_username', 190);
            $table->unique('provider_username', 'ambassador_profiles_provider_username_unique');

            $table->string('provider_customer_ref', 190)->nullable();
            $table->string('provider_driver_key', 64);

            $table->string('referral_code', 32)->unique();

            $table->boolean('flagged_for_review')->default(false);
            $table->string('flagged_reason', 255)->nullable();

            $table->timestamp('activated_at')->useCurrent();
            $table->timestamps();

            $table->index('flagged_for_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_profiles');
    }
};
