<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for Reward Milestone unlock notifications.
 *
 * One row per (member, progression cycle, milestone tier). Prevents
 * duplicate unlock emails across approval retries, queue retries, and
 * concurrent conversion events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_unlock_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ambassador_profile_id')->constrained('ambassador_profiles')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedInteger('cycle_number');
            $t->foreignId('milestone_tier_id')->constrained('reward_milestone_tiers')->cascadeOnDelete();
            $t->string('idempotency_key', 128);
            $t->string('status', 32)->default('pending'); // pending|queued|sent|failed
            $t->json('tier_snapshot')->nullable();
            $t->string('failure_class', 190)->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamps();

            $t->unique('idempotency_key', 'mun_idempotency_unique');
            $t->unique(
                ['ambassador_profile_id', 'cycle_number', 'milestone_tier_id'],
                'mun_member_cycle_tier_unique'
            );
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_unlock_notifications');
    }
};
