<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\EscalationSweeper;
use App\Domain\Operations\OperationsWriter;
use App\Domain\Operations\OperationsSpec;
use App\Enums\OperationsPriority;
use App\Enums\OperationsType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_escalates_low_priority_item_after_configured_minutes(): void
    {
        settings()->putMany([
            'ops.escalation.high_after_minutes' => '30',
            'ops.escalation.critical_after_minutes' => '60',
        ]);

        $item = app(OperationsWriter::class)->upsert(new OperationsSpec(
            type: OperationsType::OrderCredentialsUnopened,
            dedupeKey: 'test:1',
            title: 'Old item',
            priority: OperationsPriority::Low,
        ));

        // Simulate 45 minutes old
        $item->created_at = now()->subMinutes(45);
        $item->save();

        app(EscalationSweeper::class)->sweep();

        $item->refresh();
        $this->assertSame(OperationsPriority::Medium->value, $item->priority);
        $this->assertSame(1, $item->escalation_level);
        $this->assertDatabaseHas('operations_item_events', ['operations_item_id' => $item->id, 'action' => 'escalated']);
    }

    public function test_second_escalation_after_critical_threshold(): void
    {
        settings()->putMany([
            'ops.escalation.high_after_minutes' => '30',
            'ops.escalation.critical_after_minutes' => '60',
        ]);

        $item = app(OperationsWriter::class)->upsert(new OperationsSpec(
            type: OperationsType::OrderCredentialsUnopened,
            dedupeKey: 'test:2',
            title: 'Ancient item',
            priority: OperationsPriority::Low,
        ));
        $item->created_at = now()->subMinutes(75);
        $item->save();

        // First pass: L0→L1
        app(EscalationSweeper::class)->sweep();
        $item->refresh();
        $this->assertSame(1, $item->escalation_level);

        // Second pass: L1→L2
        app(EscalationSweeper::class)->sweep();
        $item->refresh();
        $this->assertSame(2, $item->escalation_level);
        $this->assertSame(OperationsPriority::High->value, $item->priority);
    }

    public function test_resolved_items_are_not_escalated(): void
    {
        $item = app(OperationsWriter::class)->upsert(new OperationsSpec(
            type: OperationsType::OrderCredentialsUnopened,
            dedupeKey: 'test:3',
            title: 'Closed item',
            priority: OperationsPriority::Low,
        ));
        $item->created_at = now()->subMinutes(90);
        $item->save();
        app(OperationsWriter::class)->resolve($item, 'done');

        app(EscalationSweeper::class)->sweep();

        $this->assertSame(0, (int) $item->refresh()->escalation_level);
    }
}
