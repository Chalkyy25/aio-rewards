<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_items', function (Blueprint $t) {
            $t->id();
            $t->string('type', 80);
            $t->string('priority', 20)->default('medium');
            $t->string('status', 20)->default('new');
            $t->string('title', 255);
            $t->text('summary')->nullable();

            // Polymorphic pointer to the related domain record
            // (Purchase, ReferralConversion, Reward, User, …). Optional so
            // adhoc / infra items (failed jobs) can exist without a subject.
            $t->nullableMorphs('subject');

            // Assignment
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();

            // Lifecycle timestamps
            $t->timestamp('first_viewed_at')->nullable();
            $t->foreignId('first_viewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->unsignedTinyInteger('escalation_level')->default(0);
            $t->timestamp('escalated_at')->nullable();

            // Resolution
            $t->text('resolution_notes')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Dedupe: stable identity so the scanner is idempotent. Only ONE
            // open row per dedupe_key at any time — enforced in code by
            // OperationsWriter (a partial index isn't portable across MySQL
            // and SQLite so we keep the invariant at the service layer).
            $t->string('dedupe_key', 190);

            // Free-form metadata (e.g. { "waited_minutes": 32 })
            $t->json('meta')->nullable();

            $t->timestamps();

            $t->index('status');
            $t->index('priority');
            $t->index('type');
            $t->index('assigned_user_id');
            $t->index(['status', 'priority']);
            $t->index('dedupe_key');
            $t->index('due_at');
        });

        Schema::create('operations_item_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('operations_item_id')->constrained('operations_items')->cascadeOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80);
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index('operations_item_id');
            $t->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_item_events');
        Schema::dropIfExists('operations_items');
    }
};
