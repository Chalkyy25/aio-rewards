<?php

namespace App\Models;

use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Enums\OperationsType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $type
 * @property string $priority
 * @property string $status
 * @property string $title
 * @property ?string $summary
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?int $assigned_user_id
 * @property ?\Illuminate\Support\Carbon $assigned_at
 * @property ?\Illuminate\Support\Carbon $first_viewed_at
 * @property ?int $first_viewed_by_user_id
 * @property ?\Illuminate\Support\Carbon $due_at
 * @property int $escalation_level
 * @property ?\Illuminate\Support\Carbon $escalated_at
 * @property ?string $resolution_notes
 * @property ?\Illuminate\Support\Carbon $resolved_at
 * @property ?int $resolved_by_user_id
 * @property string $dedupe_key
 * @property ?array $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class OperationsItem extends Model
{
    protected $table = 'operations_items';

    protected $fillable = [
        'type', 'priority', 'status', 'title', 'summary',
        'subject_type', 'subject_id',
        'assigned_user_id', 'assigned_at',
        'first_viewed_at', 'first_viewed_by_user_id',
        'due_at', 'escalation_level', 'escalated_at',
        'resolution_notes', 'resolved_at', 'resolved_by_user_id',
        'dedupe_key', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'meta' => 'array',
            'escalation_level' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationsItemEvent::class);
    }

    public function typeEnum(): ?OperationsType
    {
        return OperationsType::tryFrom($this->type);
    }

    public function priorityEnum(): OperationsPriority
    {
        return OperationsPriority::tryFrom($this->priority) ?? OperationsPriority::Medium;
    }

    public function statusEnum(): OperationsStatus
    {
        return OperationsStatus::tryFrom($this->status) ?? OperationsStatus::New;
    }

    public function isOpen(): bool
    {
        return $this->statusEnum()->isOpen();
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && $this->isOpen();
    }
}
