<?php

namespace App\Console\Commands;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use SensitiveParameter;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Interactive command to create or update the platform Super Admin.
 *
 * Contract:
 * - Prompts for name, email, and a hidden (confirmed) password.
 * - Creates or updates the user idempotently.
 * - Assigns the super_admin role.
 * - Never logs, echoes or persists the plaintext password anywhere except
 *   the resulting Argon2/bcrypt hash on `users.password`.
 * - Fails clearly if roles have not been seeded.
 */
class MakeSuperAdminCommand extends Command
{
    protected $signature = 'aio:make-super-admin';

    protected $description = 'Create or update the AIO Rewards Super Admin (interactive).';

    public function handle(): int
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('users')) {
            $this->components->error(
                'Required tables are missing. Run `php artisan migrate` first.'
            );

            return self::FAILURE;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Role::where('name', RoleEnum::SuperAdmin->value)->where('guard_name', 'web')->exists()) {
            $this->components->error(
                'Role "super_admin" is not seeded. '
                .'Run `php artisan db:seed --class=RolesAndPermissionsSeeder` first.'
            );

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = strtolower(trim((string) $this->ask('Email')));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'email' => ['required', 'email:rfc,strict', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::INVALID;
        }

        $password = $this->secretWithConfirmation();

        if ($password === null) {
            return self::INVALID;
        }

        try {
            $user = $this->upsertSuperAdmin($name, $email, $password);
        } catch (Throwable $e) {
            // Deliberately do not include $password in the exception envelope.
            $this->components->error('Failed to create/update Super Admin: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // Overwrite the local variable to shorten its residence in memory.
            $password = str_repeat("\0", 64); // phpcs:ignore
            unset($password);
        }

        $this->components->info(sprintf(
            'Super Admin "%s" <%s> is ready. 2FA enrolment is enforced on first login at /admin/login.',
            $user->name,
            $user->email,
        ));

        return self::SUCCESS;
    }

    /**
     * Prompt for a hidden password twice; validate both match and meet the
     * minimum policy. Returns null if the operator aborts or fails validation.
     */
    private function secretWithConfirmation(): ?string
    {
        $password = (string) $this->secret('Password (hidden, min 12 chars)');
        $confirm = (string) $this->secret('Confirm password');

        if ($password === '' || $confirm === '') {
            $this->components->error('Password cannot be empty.');

            return null;
        }

        if (! hash_equals($password, $confirm)) {
            $this->components->error('Passwords do not match.');

            return null;
        }

        if (strlen($password) < 12) {
            $this->components->error('Password must be at least 12 characters.');

            return null;
        }

        return $password;
    }

    /**
     * Idempotent upsert:
     * - If a user with this email exists, update name + password + activate
     *   and ensure super_admin role assigned.
     * - Otherwise create a new active, email-verified super admin.
     */
    private function upsertSuperAdmin(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
    ): User {
        return DB::transaction(function () use ($name, $email, $password): User {
            /** @var User $user */
            $user = User::firstOrNew(['email' => $email]);
            $user->name = $name;
            $user->password = Hash::make($password);
            $user->is_active = true;
            $user->email_verified_at ??= now();
            $user->save();

            if (! $user->hasRole(RoleEnum::SuperAdmin->value)) {
                $user->assignRole(RoleEnum::SuperAdmin->value);
            }

            return $user->refresh();
        });
    }
}
