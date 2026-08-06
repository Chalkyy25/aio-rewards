<?php

namespace App\Domain\Operations;

use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Enums\OperationsType;
use App\Models\OperationsItem;
use App\Models\OperationsItemEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single write path into `operations_items`. Enforces:
 *   - dedupe (only one OPEN row per dedupe_key)
 *   - status transitions (with audit trail)
 *   - assignment / resolution / dismissal semantics
 *
 * Every mutating method logs to `operations_item_events` (immutable audit
 * history) and to the global AuditLogger (unified audit stream).
 */
class OperationsWriter
{
    /**
     * Idempotently create-or-refresh an item from a scanner spec.
     * Returns the persisted item. If an open row for the same dedupe_key
     * already exists, its `meta` and `priority` are refreshed but its
     * status / assignment / first_viewed_at are preserved.
     */
    public function upsert(OperationsSpec $spec): OperationsItem
    {
        return DB::transaction(function () use ($spec): OperationsItem {
            $existing = OperationsItem::query()
                ->where('dedupe_key', $spec->dedupeKey)
                ->whereIn('status', OperationsStatus::openValues())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->fill([
                    'title' => $spec->title,
                    'summary' => $spec->summary,
                    'meta' => array_merge((array) $existing->meta, $spec->meta),
                ]);
                if ($spec->dueAt !== null) {
                    $existing->due_at = $spec->dueAt;
                }
                $existing->save();

                return $existing;
            }

            $priority = $spec->priority ?? $spec->type->defaultPriority();

            $item = OperationsItem::create([
                'type' => $spec->type->value,
                'priority' => $priority->value,
                'status' => OperationsStatus::New->value,
                'title' => $spec->title,
                'summary' => $spec->summary,
                'subject_type' => $spec->subject?->getMorphClass(),
                'subject_id' => $spec->subject?->getKey(),
                'due_at' => $spec->dueAt,
                'dedupe_key' => $spec->dedupeKey,
                'meta' => $spec->meta,
            ]);

            $this->recordEvent($item, 'created', ['auto' => true]);
            AuditLogger::record(action: 'ops.item.created', subject: $item, after: [
                'type' => $item->type, 'priority' => $item->priority, 'dedupe_key' => $item->dedupe_key,
            ]);

            return $item;
        });
    }

    /**
     * Auto-resolve any OPEN items with the given dedupe_key. Called by the
     * scanner when the underlying condition has cleared (e.g. an order was
     * completed) so the queue self-cleans.
     */
    public function autoResolve(string $dedupeKey, string $reason = 'condition cleared'): int
    {
        $count = 0;
        OperationsItem::query()
            ->where('dedupe_key', $dedupeKey)
            ->whereIn('status', OperationsStatus::openValues())
            ->get()
            ->each(function (OperationsItem $item) use ($reason, &$count) {
                $item->status = OperationsStatus::Resolved->value;
                $item->resolved_at = now();
                $item->resolution_notes = $item->resolution_notes ?: 'Auto-resolved: '.$reason;
                $item->save();
                $this->recordEvent($item, 'auto_resolved', ['reason' => $reason]);
                AuditLogger::record(action: 'ops.item.auto_resolved', subject: $item, after: ['reason' => $reason]);
                $count++;
            });

        return $count;
    }

    public function markSeen(OperationsItem $item, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        if ($item->first_viewed_at !== null) {
            return;
        }
        $item->first_viewed_at = now();
        $item->first_viewed_by_user_id = $actor?->getKey();
        if ($item->statusEnum() === OperationsStatus::New) {
            $item->status = OperationsStatus::Seen->value;
        }
        $item->save();
        $this->recordEvent($item, 'seen', [], $actor);
    }

    public function assign(OperationsItem $item, User $assignee, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        $before = ['assigned_user_id' => $item->assigned_user_id];
        $item->assigned_user_id = $assignee->getKey();
        $item->assigned_at = now();
        if (in_array($item->statusEnum(), [OperationsStatus::New, OperationsStatus::Seen], true)) {
            $item->status = OperationsStatus::Assigned->value;
        }
        $item->save();
        $this->recordEvent($item, 'assigned', ['to_user_id' => $assignee->getKey(), 'to_email' => $assignee->email], $actor);
        AuditLogger::record(action: 'ops.item.assigned', subject: $item, before: $before, after: ['assigned_user_id' => $assignee->getKey()], actor: $actor);
    }

    public function startProgress(OperationsItem $item, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        if ($item->statusEnum() === OperationsStatus::InProgress) {
            return;
        }
        $item->status = OperationsStatus::InProgress->value;
        if ($item->assigned_user_id === null && $actor) {
            $item->assigned_user_id = $actor->getKey();
            $item->assigned_at = now();
        }
        $item->save();
        $this->recordEvent($item, 'started', [], $actor);
    }

    public function resolve(OperationsItem $item, ?string $notes, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        $item->status = OperationsStatus::Resolved->value;
        $item->resolved_at = now();
        $item->resolved_by_user_id = $actor?->getKey();
        if ($notes !== null && $notes !== '') {
            $item->resolution_notes = $notes;
        }
        $item->save();
        $this->recordEvent($item, 'resolved', ['notes' => $notes], $actor);
        AuditLogger::record(action: 'ops.item.resolved', subject: $item, after: ['notes' => $notes], actor: $actor);
    }

    public function dismiss(OperationsItem $item, ?string $notes, ?User $actor = null): void
    {
        $actor ??= Auth::user();
        $item->status = OperationsStatus::Dismissed->value;
        $item->resolved_at = now();
        $item->resolved_by_user_id = $actor?->getKey();
        if ($notes !== null && $notes !== '') {
            $item->resolution_notes = $notes;
        }
        $item->save();
        $this->recordEvent($item, 'dismissed', ['notes' => $notes], $actor);
        AuditLogger::record(action: 'ops.item.dismissed', subject: $item, after: ['notes' => $notes], actor: $actor);
    }

    public function escalate(OperationsItem $item, string $reason): void
    {
        $prev = $item->priorityEnum();
        $newPriority = $prev->escalate();
        $item->priority = $newPriority->value;
        $item->escalation_level = ($item->escalation_level ?? 0) + 1;
        $item->escalated_at = now();
        $item->save();
        $this->recordEvent($item, 'escalated', [
            'from' => $prev->value,
            'to' => $newPriority->value,
            'level' => $item->escalation_level,
            'reason' => $reason,
        ]);
        AuditLogger::record(action: 'ops.item.escalated', subject: $item, after: [
            'from_priority' => $prev->value,
            'to_priority' => $newPriority->value,
            'level' => $item->escalation_level,
        ]);
    }

    /**
     * Create an ad-hoc item (e.g. from an admin action rather than a
     * scanner). Same dedupe rules apply.
     */
    public function manualCreate(
        OperationsType $type,
        string $title,
        ?string $summary = null,
        ?OperationsPriority $priority = null,
        ?\Illuminate\Database\Eloquent\Model $subject = null,
        ?\DateTimeInterface $dueAt = null,
        array $meta = [],
        ?User $actor = null,
    ): OperationsItem {
        $actor ??= Auth::user();
        $dedupe = $type->value.':manual:'.uniqid('', true);

        $item = $this->upsert(new OperationsSpec(
            type: $type,
            dedupeKey: $dedupe,
            title: $title,
            summary: $summary,
            priority: $priority,
            subject: $subject,
            dueAt: $dueAt,
            meta: array_merge(['manual' => true], $meta),
        ));
        $this->recordEvent($item, 'created_manual', ['title' => $title], $actor);

        return $item;
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(OperationsItem $item, string $action, array $payload = [], ?User $actor = null): void
    {
        OperationsItemEvent::create([
            'operations_item_id' => $item->id,
            'actor_user_id' => ($actor ?? Auth::user())?->getKey(),
            'action' => $action,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }
}
