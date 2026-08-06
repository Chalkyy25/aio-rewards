<?php

namespace Tests\Feature\Activation;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Services\AmbassadorActivationService;
use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Listeners\SendAmbassadorWelcomeAfterVerified;
use App\Livewire\EmailVerificationWaiting;
use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Notifications\AmbassadorWelcomeNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end coverage of the "activation → verify email → welcome email"
 * flow. Each test locks in one line-item from the specification.
 */
class AmbassadorVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
    }

    private function activate(string $email = 'alice@example.com', string $username = 'test_active'): AmbassadorProfile
    {
        return app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: $username,
            providerPassword: 'letmein',
            email: $email,
            name: 'Alice',
            newPassword: 'newSecret1234',
        ));
    }

    private function signedVerifyUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
    }

    // ── Activation-time behaviour ────────────────────────────────────────

    /** 1. Activation sends the verify email. */
    public function test_activation_sends_verify_email(): void
    {
        Notification::fake();
        $a = $this->activate();
        Notification::assertSentTo($a->user, VerifyEmail::class);
    }

    /** 2. Activation does NOT send the welcome email. */
    public function test_activation_does_not_send_welcome_email(): void
    {
        Notification::fake();
        $a = $this->activate();
        Notification::assertNotSentTo($a->user, AmbassadorWelcomeNotification::class);
    }

    /** 3. email_verified_at stays null until the user verifies. */
    public function test_email_verified_at_is_null_after_activation(): void
    {
        Notification::fake();
        $a = $this->activate();
        $this->assertNull($a->user->refresh()->email_verified_at);
        $this->assertNull($a->user->refresh()->welcome_email_sent_at);
    }

    /** 4. Verifying via signed URL sets email_verified_at. */
    public function test_signed_url_sets_email_verified_at(): void
    {
        Notification::fake();
        $a = $this->activate();

        $this->actingAs($a->user)->get($this->signedVerifyUrl($a->user))->assertRedirect();

        $this->assertNotNull($a->user->refresh()->email_verified_at);
    }

    // ── Verified → welcome dispatch ──────────────────────────────────────

    /** 5. Verified event triggers the welcome notification. */
    public function test_verified_event_dispatches_welcome_notification(): void
    {
        Notification::fake();
        $a = $this->activate();

        event(new Verified($a->user->refresh()));

        Notification::assertSentTo($a->user, AmbassadorWelcomeNotification::class);
    }

    /** 6-8. Welcome email content contains referral code, referral URL and dashboard URL. */
    public function test_welcome_email_contains_referral_and_dashboard_url(): void
    {
        $a = $this->activate();
        $mail = (new AmbassadorWelcomeNotification($a))->toMail($a->user);
        $rendered = implode("\n", $mail->introLines);

        $this->assertStringContainsString($a->referral_code, $rendered);
        $this->assertStringContainsString($a->referralUrl(), $rendered);
        $this->assertContains('Open your dashboard', array_column($mail->actionText ? [['t' => $mail->actionText]] : [], 't') ?: [$mail->actionText]);
        $this->assertSame(route('ambassador.dashboard'), $mail->actionUrl);
    }

    /** 9. Welcome email no longer asks the user to verify. */
    public function test_welcome_email_does_not_ask_user_to_verify_email(): void
    {
        $a = $this->activate();
        $mail = (new AmbassadorWelcomeNotification($a))->toMail($a->user);
        $body = strtolower(implode(' ', $mail->introLines).' '.strtolower((string) $mail->subject));

        $this->assertStringNotContainsString('verify your email', $body);
        $this->assertStringNotContainsString('verify their email', $body);
        $this->assertStringNotContainsString('separate verification email', $body);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    /** 10 + 11. Duplicate Verified events do NOT re-send the welcome. */
    public function test_duplicate_verified_events_send_welcome_email_only_once(): void
    {
        Notification::fake();
        $a = $this->activate();
        $u = $a->user->refresh();

        // Simulate a click, then a reload of the verification URL, then a
        // replayed queue job — three Verified events in a row.
        event(new Verified($u));
        event(new Verified($u));
        event(new Verified($u));

        Notification::assertSentToTimes($u, AmbassadorWelcomeNotification::class, 1);
        $this->assertNotNull($u->refresh()->welcome_email_sent_at);
    }

    /** 12. Non-ambassador users do not receive the welcome. */
    public function test_non_ambassador_user_does_not_receive_welcome_email(): void
    {
        Notification::fake();
        $u = User::factory()->create(); // no ambassador role

        event(new Verified($u));

        Notification::assertNotSentTo($u, AmbassadorWelcomeNotification::class);
        $this->assertNull($u->refresh()->welcome_email_sent_at);
    }

    // ── Waiting page polling ─────────────────────────────────────────────

    /** 13. The waiting page detects a DB change on the next poll. */
    public function test_waiting_page_detects_verification_from_the_database(): void
    {
        $a = $this->activate();

        $component = Livewire::actingAs($a->user->refresh())
            ->test(EmailVerificationWaiting::class)
            ->assertSet('verified', false);

        // Simulate: another device just marked this user verified.
        User::query()->whereKey($a->user->id)->update(['email_verified_at' => now()]);

        $component->call('checkVerified')
            ->assertRedirect(route('ambassador.dashboard'));
    }

    /** 14. Cross-device verification is picked up by the original waiting page. */
    public function test_cross_device_verification_is_picked_up_by_waiting_page(): void
    {
        $a = $this->activate();
        $laptopUser = $a->user->refresh();

        // Laptop opens the waiting page.
        $waiting = Livewire::actingAs($laptopUser)->test(EmailVerificationWaiting::class);

        // "Phone" (a browser with NO session on this device) hits the signed link.
        auth()->logout();
        $this->get($this->signedVerifyUrl($laptopUser))
            ->assertOk()
            ->assertViewIs('auth.verification-success');

        // The next poll on the laptop must redirect.
        auth()->login($laptopUser->fresh());
        $waiting->call('checkVerified')->assertRedirect(route('ambassador.dashboard'));
    }

    /** 15. Once detected, the waiting page redirects to the dashboard. */
    public function test_waiting_page_redirects_to_dashboard_when_verified(): void
    {
        $a = $this->activate();
        User::query()->whereKey($a->user->id)->update(['email_verified_at' => now()]);

        Livewire::actingAs($a->user->refresh())
            ->test(EmailVerificationWaiting::class)
            ->assertRedirect(route('ambassador.dashboard'));
    }

    /** 16. An already-verified visitor to /email/verify is redirected immediately. */
    public function test_already_verified_user_is_redirected_from_notice_route(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->assignRole(RoleEnum::Ambassador->value);

        $this->actingAs($u)->get(route('verification.notice'))->assertRedirect(route('ambassador.dashboard'));
    }

    // ── Resend + middleware preserved ────────────────────────────────────

    /** 17. Resend continues to work. */
    public function test_resend_still_works(): void
    {
        $a = $this->activate();
        Notification::fake(); // start faking AFTER activation so we only count the resend

        $this->actingAs($a->user)->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentToTimes($a->user, VerifyEmail::class, 1);
    }

    /** 18. Verified middleware still guards the ambassador dashboard. */
    public function test_verified_middleware_still_guards_dashboard(): void
    {
        $a = $this->activate();

        // Unverified user is bounced back to the notice.
        $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))
            ->assertRedirect(route('verification.notice'));

        // Verify, then confirm they're allowed in.
        User::query()->whereKey($a->user->id)->update(['email_verified_at' => now()]);
        $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))
            ->assertOk();
    }

    // ── Existing behaviour preserved ─────────────────────────────────────

    /** 19. Provider verification / activation exception behaviour unchanged. */
    public function test_provider_rejection_still_blocks_activation(): void
    {
        Notification::fake();

        $this->expectException(\App\Domain\Ambassadors\Exceptions\ActivationException::class);
        try {
            app(AmbassadorActivationService::class)->activate(new ActivationInput(
                providerUsername: 'test_inactive',
                providerPassword: 'letmein',
                email: 'r@example.com',
                name: 'R',
                newPassword: 'aValidPass1234',
            ));
        } finally {
            $this->assertDatabaseMissing('users', ['email' => 'r@example.com']);
            Notification::assertNothingSent();
        }
    }

    /** 20. Referral generation still yields a stable, non-empty code. */
    public function test_referral_code_is_generated_and_persisted(): void
    {
        $a = $this->activate();
        $this->assertNotEmpty($a->referral_code);
        $this->assertSame($a->referral_code, $a->fresh()->referral_code);
    }

    // ── Extra: signed URL from wrong device does the right thing ────────

    public function test_signed_url_hit_by_wrong_device_shows_success_page_and_marks_verified(): void
    {
        $a = $this->activate();

        // Guest browser (no acting user)
        $this->get($this->signedVerifyUrl($a->user))
            ->assertOk()
            ->assertViewIs('auth.verification-success');

        $this->assertNotNull($a->user->refresh()->email_verified_at);
    }

    public function test_listener_class_is_registered_for_verified_event(): void
    {
        // The listener must be wired to Illuminate\Auth\Events\Verified.
        $listeners = Event::getListeners(Verified::class);
        $this->assertNotEmpty($listeners, 'No listeners registered for Verified event.');
    }

    public function test_welcome_email_failure_releases_marker(): void
    {
        // If notify() throws, welcome_email_sent_at must be reset so a later
        // Verified event can retry.
        $a = $this->activate();

        // Force notify() to throw by using an in-process listener that raises.
        Notification::fake();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('mail broken'));

        try {
            (new SendAmbassadorWelcomeAfterVerified)->handle(new Verified($a->user->refresh()));
        } catch (\Throwable) {
            // expected
        }

        $this->assertNull($a->user->refresh()->welcome_email_sent_at);
    }
}
