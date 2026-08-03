<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logger_writes_row_with_actor_and_context(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $log = AuditLogger::record(
            action: 'test.event',
            before: ['status' => 'pending'],
            after: ['status' => 'approved'],
            context: ['note' => 'from unit test'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'test.event',
            'actor_user_id' => $actor->id,
        ]);

        $fresh = $log->fresh();
        $this->assertSame(['status' => 'pending'], $fresh->before);
        $this->assertSame(['status' => 'approved'], $fresh->after);
        $this->assertSame(['note' => 'from unit test'], $fresh->context);
    }

    public function test_audit_logger_supports_system_actor_when_unauthenticated(): void
    {
        $log = AuditLogger::record(action: 'system.event');

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'system.event',
            'actor_user_id' => null,
        ]);
    }
}
