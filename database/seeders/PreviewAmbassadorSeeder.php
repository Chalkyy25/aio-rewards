<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a pre-verified ambassador account for preview / QA use.
 *
 * NOT run by the default DatabaseSeeder. Invoke explicitly:
 *   php artisan db:seed --class=PreviewAmbassadorSeeder
 *
 * Credentials are documented in memory/test_credentials.md.
 */
class PreviewAmbassadorSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'preview-verified@example.com';

        /** @var User $user */
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => 'Preview Verified Ambassador',
            'email' => $email,
            'password' => Hash::make('previewpass1234'),
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        if (! $user->hasRole(RoleEnum::Ambassador->value)) {
            $user->assignRole(RoleEnum::Ambassador->value);
        }

        AmbassadorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'provider_username' => 'preview_test_user',
                'provider_customer_ref' => 'fake-ref-preview',
                'provider_driver_key' => 'fake',
                'referral_code' => 'PREVIEW1',
                'activated_at' => now(),
            ]
        );

        $this->command->info("Preview ambassador ready: {$email} / previewpass1234 / PREVIEW1");
    }
}
