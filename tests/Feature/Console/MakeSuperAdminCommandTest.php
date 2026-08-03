<?php

namespace Tests\Feature\Console;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_roles_are_not_seeded(): void
    {
        $this->artisan('aio:make-super-admin')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_creates_super_admin_when_none_exists(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('aio:make-super-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password (hidden, min 12 chars)', 'correcthorsebattery')
            ->expectsQuestion('Confirm password', 'correcthorsebattery')
            ->assertExitCode(0);

        $user = User::where('email', 'alice@example.com')->firstOrFail();
        $this->assertSame('Alice Admin', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole(RoleEnum::SuperAdmin->value));
        $this->assertTrue(Hash::check('correcthorsebattery', $user->password));
    }

    public function test_command_is_idempotent_and_updates_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('aio:make-super-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password (hidden, min 12 chars)', 'firstpassword12')
            ->expectsQuestion('Confirm password', 'firstpassword12')
            ->assertExitCode(0);

        $this->artisan('aio:make-super-admin')
            ->expectsQuestion('Name', 'Alice Admin Renamed')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password (hidden, min 12 chars)', 'secondpassword123')
            ->expectsQuestion('Confirm password', 'secondpassword123')
            ->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'alice@example.com')->count());

        $user = User::where('email', 'alice@example.com')->firstOrFail();
        $this->assertSame('Alice Admin Renamed', $user->name);
        $this->assertTrue(Hash::check('secondpassword123', $user->password));
        $this->assertFalse(Hash::check('firstpassword12', $user->password));
        $this->assertTrue($user->hasRole(RoleEnum::SuperAdmin->value));
    }

    public function test_rejects_mismatched_passwords(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('aio:make-super-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password (hidden, min 12 chars)', 'correcthorsebattery')
            ->expectsQuestion('Confirm password', 'differentpassword12')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('users', ['email' => 'alice@example.com']);
    }

    public function test_rejects_short_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('aio:make-super-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password (hidden, min 12 chars)', 'shortpw')
            ->expectsQuestion('Confirm password', 'shortpw')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('users', ['email' => 'alice@example.com']);
    }

    public function test_rejects_invalid_email(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->artisan('aio:make-super-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'not-an-email')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }
}
