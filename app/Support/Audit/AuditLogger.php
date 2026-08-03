<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Central entry point for writing audit-log rows.
 *
 * Services should call AuditLogger::record(...) rather than instantiating
 * AuditLog directly, so the actor/IP/user-agent capture is consistent.
 *
 * Never pass secrets (provider passwords, Stripe keys, webhook secrets)
 * in $before/$after/$context. This is a design contract enforced by review.
 */
final class AuditLogger
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $context
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
        ?Authenticatable $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512) ?: null,
            'context' => $context,
        ]);
    }
}
