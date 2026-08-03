<?php

namespace Tests\Feature\Activation;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Exceptions\ActivationException;
use App\Domain\Ambassadors\Services\AmbassadorActivationService;
use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Notifications\AmbassadorWelcomeNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AmbassadorActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
    }

    public function test_happy_path_creates_user_profile_role_and_sends_welcome_and_verify(): void
    {
        Notification::fake();

        $service = app(AmbassadorActivationService::class);
        $ambassador = $service->activate(new ActivationInput(
            providerUsername: 'test_active',
            providerPassword: 'letmein',
            email: 'alice@example.com',
            name: 'Alice',
            newPassword: 'newSecret1234',
        ));

        $this->assertInstanceOf(AmbassadorProfile::class, $ambassador);
        $this->assertSame('alice@example.com', $ambassador->user->email);
        $this->assertTrue($ambassador->user->hasRole(RoleEnum::Ambassador->value));
        $this->assertTrue(Hash::check('newSecret1234', $ambassador->user->password));
        $this->assertTrue($ambassador->user->is_active);
        $this->assertNotEmpty($ambassador->referral_code);
        $this->assertSame('fake', $ambassador->provider_driver_key);

        Notification::assertSentTo($ambassador->user, VerifyEmail::class);
        Notification::assertSentTo($ambassador->user, AmbassadorWelcomeNotification::class);
    }

    public function test_provider_password_is_never_persisted_in_users_ambassadors_or_audit_logs(): void
    {
        // Rule: username matches but ANY password is accepted, so the test can
        // pass a distinctive provider password and then grep the DB for it.
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver([
            'test_active' => ['password' => '__any__', 'result' => 'eligible'],
        ]));

        $service = app(AmbassadorActivationService::class);
        $service->activate(new ActivationInput(
            providerUsername: 'test_active',
            providerPassword: 'super-secret-provider-pw',
            email: 'bob@example.com',
            name: 'Bob',
            newPassword: 'accountPassword123',
        ));

        $this->assertDatabaseMissing('users', ['password' => 'super-secret-provider-pw']);
        $this->assertDatabaseMissing('ambassador_profiles', ['provider_customer_ref' => 'super-secret-provider-pw']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ambassador.activated', 'context' => 'super-secret-provider-pw']);

        // Broad sweep: no row anywhere in these three tables should contain the plaintext.
        $needle = 'super-secret-provider-pw';
        $this->assertFalse(
            DB::table('users')->where('password', 'like', "%{$needle}%")->exists()
        );
        $this->assertFalse(
            DB::table('ambassador_profiles')->where(function ($q) use ($needle) {
                $q->where('provider_username', 'like', "%{$needle}%")
                    ->orWhere('provider_customer_ref', 'like', "%{$needle}%")
                    ->orWhere('provider_driver_key', 'like', "%{$needle}%")
                    ->orWhere('flagged_reason', 'like', "%{$needle}%");
            })->exists()
        );
        $this->assertFalse(
            DB::table('audit_logs')->where(function ($q) use ($needle) {
                $q->where('action', 'like', "%{$needle}%")
                    ->orWhere('before', 'like', "%{$needle}%")
                    ->orWhere('after', 'like', "%{$needle}%")
                    ->orWhere('context', 'like', "%{$needle}%");
            })->exists()
        );
    }

    public function test_rejects_when_provider_says_inactive(): void
    {
        $this->expectException(ActivationException::class);
        app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'test_inactive',
            providerPassword: 'letmein',
            email: 'c@example.com',
            name: 'C',
            newPassword: 'aValidPass1234',
        ));

        $this->assertDatabaseMissing('users', ['email' => 'c@example.com']);
    }

    public function test_rejects_when_credentials_wrong(): void
    {
        try {
            app(AmbassadorActivationService::class)->activate(new ActivationInput(
                providerUsername: 'test_active',
                providerPassword: 'wrong',
                email: 'd@example.com',
                name: 'D',
                newPassword: 'aValidPass1234',
            ));
            $this->fail('Expected ActivationException');
        } catch (ActivationException $e) {
            $this->assertSame('provider_rejected', $e->reasonKey);
            $this->assertDatabaseMissing('users', ['email' => 'd@example.com']);
        }
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->expectException(ActivationException::class);
        app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'test_active',
            providerPassword: 'letmein',
            email: 'DUP@example.com',
            name: 'Dup',
            newPassword: 'aValidPass1234',
        ));
    }

    public function test_rejects_duplicate_provider_username_case_insensitively(): void
    {
        app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'Test_Active',
            providerPassword: 'letmein',
            email: 'first@example.com',
            name: 'First',
            newPassword: 'aValidPass1234',
        ));

        // Add a second rule so the second attempt can pass provider verification.
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver([
            'test_active' => ['password' => 'letmein', 'result' => 'eligible'],
        ]));

        $this->expectException(ActivationException::class);
        app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'TEST_ACTIVE',
            providerPassword: 'letmein',
            email: 'second@example.com',
            name: 'Second',
            newPassword: 'aValidPass1234',
        ));
    }

    public function test_referral_codes_are_unique_across_ambassadors(): void
    {
        $service = app(AmbassadorActivationService::class);

        $a = $service->activate(new ActivationInput('test_active', 'letmein', 'a@example.com', 'A', 'aValidPass1234'));

        // Add a second eligible username for the second run.
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver([
            'test_active' => ['password' => 'letmein', 'result' => 'not_found'],
            'test_active_2' => ['password' => 'letmein', 'result' => 'eligible'],
        ]));

        $b = app(AmbassadorActivationService::class)->activate(new ActivationInput('test_active_2', 'letmein', 'b@example.com', 'B', 'aValidPass1234'));

        $this->assertNotSame($a->referral_code, $b->referral_code);
    }
}
