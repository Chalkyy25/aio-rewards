<?php

namespace App\Domain\Admin;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Guardrails around modifying admin/super-admin membership.
 *
 * Rules:
 *   - The system MUST always retain at least one active Super Admin.
 *   - No user may demote or deactivate the final active Super Admin.
 *   - No user may delete the final active Super Admin.
 */
class AdminUserGuard
{
    public static function ensureNotLastSuperAdmin(User $user, ?string $reason = null): void
    {
        if (! $user->hasRole(RoleEnum::SuperAdmin->value) || ! $user->is_active) {
            return;
        }

        $others = User::role(RoleEnum::SuperAdmin->value)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($others === 0) {
            throw new DomainException(
                'Cannot '.($reason ?: 'modify').' the last active Super Admin. Promote another Super Admin first.',
            );
        }
    }

    /**
     * Sync a user's assignable roles ({ambassador, support, admin, super_admin})
     * to the exact list provided. Throws if it would strand the system without
     * an active Super Admin.
     *
     * @param  list<string>  $roles
     */
    public static function syncRoles(User $user, array $roles, ?User $actor = null): void
    {
        $roles = array_values(array_unique(array_intersect($roles, [
            RoleEnum::Ambassador->value,
            RoleEnum::Support->value,
            RoleEnum::Admin->value,
            RoleEnum::SuperAdmin->value,
        ])));

        // If we are removing SuperAdmin from this user, ensure the guarantee holds.
        if ($user->hasRole(RoleEnum::SuperAdmin->value) && ! in_array(RoleEnum::SuperAdmin->value, $roles, true)) {
            self::ensureNotLastSuperAdmin($user, 'demote');
        }

        DB::transaction(function () use ($user, $roles): void {
            $user->syncRoles($roles);
        });

        AuditLogger::record(
            action: 'user.roles_synced',
            subject: $user,
            after: ['roles' => $roles],
            actor: $actor,
        );
    }

    public static function setActive(User $user, bool $active, ?User $actor = null): void
    {
        if (! $active) {
            self::ensureNotLastSuperAdmin($user, 'deactivate');
        }
        $user->update(['is_active' => $active]);
        AuditLogger::record(
            action: $active ? 'user.activated' : 'user.deactivated',
            subject: $user,
            actor: $actor,
        );
    }

    public static function delete(User $user, ?User $actor = null): void
    {
        self::ensureNotLastSuperAdmin($user, 'delete');
        AuditLogger::record('user.deleted', $user, actor: $actor);
        $user->delete();
    }

    /** @return list<string> */
    public static function assignableRoles(): array
    {
        return SpatieRole::query()->pluck('name')->all();
    }
}
