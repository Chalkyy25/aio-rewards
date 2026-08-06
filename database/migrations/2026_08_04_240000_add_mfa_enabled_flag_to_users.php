<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Opt-in MFA flag. Super Admins are always required regardless.
            $t->boolean('mfa_enabled')->default(false)->after('is_active');
            $t->timestamp('mfa_enabled_at')->nullable()->after('mfa_enabled');
        });

        // Existing admin-tier users keep MFA enabled by default (the previous
        // behaviour was mandatory MFA for every panel user).
        \Illuminate\Support\Facades\DB::table('users')
            ->whereIn('id', function ($q) {
                $q->select('model_id')->from('model_has_roles')
                  ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                  ->whereIn('roles.name', ['admin', 'super_admin', 'support']);
            })
            ->update(['mfa_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['mfa_enabled', 'mfa_enabled_at']);
        });
    }
};
