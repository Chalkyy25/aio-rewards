<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted ledger that binds ReferralConversions to a Reward claim /
 * progression cycle so the same qualifying referrals cannot fund two
 * overlapping payouts.
 *
 * `active_marker` is set to 1 while the allocation is active and NULL
 * once released, giving us a portable partial-unique on
 * (referral_conversion_id, active_marker) — MySQL/MariaDB treat NULL as
 * distinct, so multiple released rows for the same conversion are OK
 * but at most one may be active at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('referral_conversion_id')->constrained('referral_conversions')->cascadeOnDelete();
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles');
            $t->unsignedInteger('cycle_number');
            $t->foreignId('reward_id')->nullable()->constrained('rewards')->nullOnDelete();
            $t->unsignedTinyInteger('active_marker')->nullable(); // 1=active, NULL=released
            $t->timestamp('allocated_at')->useCurrent();
            $t->timestamp('released_at')->nullable();
            $t->string('release_reason', 64)->nullable();
            $t->timestamps();

            $t->unique(['referral_conversion_id', 'active_marker'], 'ra_conv_active_unique');
            $t->index(['ambassador_profile_id', 'cycle_number']);
            $t->index(['reward_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_allocations');
    }
};
