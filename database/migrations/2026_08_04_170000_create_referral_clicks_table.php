<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_clicks', function (Blueprint $table) {
            $table->id();

            // Nullable so we can persist rows for invalid codes if we ever
            // want to (currently we don't). If the ambassador is later hard
            // deleted, keep the click for analytics but null the FK.
            $table->foreignId('ambassador_profile_id')
                ->constrained('ambassador_profiles')
                ->cascadeOnDelete();

            $table->string('referral_code_snapshot', 32);
            $table->ulid('attribution_id')->unique();

            // hash_hmac('sha256', $ip, app.key). 64 hex chars.
            $table->char('ip_hash', 64);

            $table->string('user_agent', 512)->nullable();
            $table->string('referer_url', 512)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();

            $table->boolean('is_bot')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index('ambassador_profile_id');
            $table->index('referral_code_snapshot');
            $table->index('is_bot');
            $table->index('created_at');
            $table->index(['ambassador_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_clicks');
    }
};
