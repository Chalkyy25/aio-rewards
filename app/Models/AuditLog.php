<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable audit trail for sensitive admin actions.
 *
 * Rows are append-only from the application layer. The MVP relies on service
 * discipline (no update()/delete() calls anywhere) rather than DB-level GRANTs;
 * a hardening ticket in Phase 8 will remove UPDATE/DELETE privileges on this
 * table for the runtime DB user.
 */
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'actor_user_id',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'ip',
        'user_agent',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
