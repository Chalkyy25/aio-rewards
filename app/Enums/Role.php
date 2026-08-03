<?php

namespace App\Enums;

/**
 * Application roles. Kept as a first-class enum so policies, seeders and
 * Filament panel access checks all reference the same source of truth.
 *
 * `Support` is defined for permission-matrix continuity but is not assigned
 * to any user in the MVP.
 */
enum Role: string
{
    case Ambassador = 'ambassador';
    case Support = 'support';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    /**
     * Roles allowed to sign into the Filament admin panel.
     *
     * @return array<int, string>
     */
    public static function panelRoles(): array
    {
        return [self::Support->value, self::Admin->value, self::SuperAdmin->value];
    }
}
