<?php

namespace Tests\Feature\Terminology;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Services\AmbassadorActivationService;
use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Enums\Role as RoleEnum;
use App\Livewire\AmbassadorActivation;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\User;
use App\Notifications\AmbassadorWelcomeNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guarantees the customer-facing copy has been switched from
 * "Ambassador" → "AIO Rewards Member" without breaking any of the
 * internal identifiers (routes, models, columns, roles, class names)
 * that the rest of the app still relies on.
 */
class RewardsMemberTerminologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
    }

    private function activate(string $email = 'alice@example.com'): AmbassadorProfile
    {
        return app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'test_active',
            providerPassword: 'letmein',
            email: $email,
            name: 'Alice',
            newPassword: 'newSecret1234',
        ));
    }

    /** Helper: return only the visible text of an HTML response (attrs & JS/CSS blocks stripped). */
    private function visibleText(string $html): string
    {
        $s = preg_replace('/\s[a-zA-Z_:-]+="[^"]*"/', '', $html) ?? $html;
        $s = preg_replace("/\s[a-zA-Z_:-]+='[^']*'/", '', $s) ?? $s;
        $s = preg_replace('#<script.*?</script>#si', '', $s) ?? $s;
        $s = preg_replace('#<style.*?</style>#si', '', $s) ?? $s;

        return strip_tags($s);
    }

    // ── Public content ───────────────────────────────────────────────────

    public function test_public_landing_page_does_not_mention_ambassador_to_customers(): void
    {
        $body = $this->visibleText($this->get(route('home'))->assertOk()->getContent());

        $this->assertStringNotContainsStringIgnoringCase('ambassador', $body);
        $this->assertStringContainsString('Join AIO Rewards', $body);
    }

    public function test_public_landing_shows_ambassador_free_referral_banner(): void
    {
        $body = $this->visibleText(
            $this->withCookie(config('referrals.cookie.name', 'aior_ref'), 'nonexistent')
                ->get(route('home'))
                ->assertOk()
                ->getContent()
        );

        $this->assertStringContainsString('AIO Rewards member', $body);
        $this->assertStringNotContainsStringIgnoringCase('ambassador', $body);
    }

    public function test_referral_unavailable_page_uses_rewards_member_wording(): void
    {
        $body = $this->visibleText($this->get('/r/nosuchcode')->getContent());

        $this->assertStringNotContainsStringIgnoringCase('ambassador', $body);
        $this->assertStringContainsString('Rewards Member', $body);
    }

    // ── Activation page ──────────────────────────────────────────────────

    public function test_activation_page_uses_join_aio_rewards(): void
    {
        Livewire::test(AmbassadorActivation::class)
            ->assertSee('Join AIO Rewards')
            ->assertDontSee('Activate your Ambassador account')
            ->assertDontSee('Already an ambassador?');
    }

    // ── Login page ───────────────────────────────────────────────────────

    public function test_login_page_uses_join_aio_rewards_link(): void
    {
        $body = $this->visibleText($this->get(route('login'))->assertOk()->getContent());

        $this->assertStringContainsString('Join AIO Rewards', $body);
        $this->assertStringNotContainsString('Activate your account', $body);
    }

    // ── Waiting page ─────────────────────────────────────────────────────

    public function test_verification_waiting_page_uses_aio_rewards_wording(): void
    {
        $a = $this->activate();
        $body = $this->visibleText($this->actingAs($a->user)->get(route('verification.notice'))->getContent());

        $this->assertStringContainsString('Thanks for joining AIO Rewards', $body);
        $this->assertStringNotContainsString('Thanks for activating your Ambassador account', $body);
        $this->assertStringContainsString('Rewards dashboard', $body);
    }

    // ── Rewards dashboard ────────────────────────────────────────────────

    public function test_rewards_dashboard_uses_rewards_wording(): void
    {
        $a = $this->activate();
        // Verify so we can pass the `verified` middleware.
        User::query()->whereKey($a->user->id)->update(['email_verified_at' => now()]);

        $body = $this->visibleText(
            $this->actingAs($a->user->refresh())
                ->get(route('ambassador.dashboard'))
                ->assertOk()
                ->getContent()
        );

        // Nav label
        $this->assertStringContainsString('My Rewards', $body);
        // Dashboard subtitle
        $this->assertStringContainsString('Rewards dashboard', $body);
        // No customer-visible "ambassador" copy remains on the page.
        $this->assertStringNotContainsStringIgnoringCase('ambassador', $body);
    }

    // ── Welcome email ────────────────────────────────────────────────────

    public function test_welcome_email_uses_aio_rewards_account_and_no_ambassador_wording(): void
    {
        $a = $this->activate();
        $mail = (new AmbassadorWelcomeNotification($a))->toMail($a->user);
        $body = strtolower(implode(' ', $mail->introLines).' '.$mail->actionText.' '.$mail->subject);

        $this->assertStringContainsString('aio rewards account is ready', $body);
        $this->assertStringNotContainsString('ambassador', $body);
        // Action button uses the new label
        $this->assertSame('Open your dashboard', $mail->actionText);
    }

    public function test_conversion_approved_email_uses_open_my_rewards(): void
    {
        $a = $this->activate();
        $pkg = Package::factory()->create();
        $purchase = Purchase::factory()->paid()->create(['package_id' => $pkg->id]);
        $conv = \App\Models\ReferralConversion::create([
            'purchase_id' => $purchase->id,
            'ambassador_profile_id' => $a->id,
            'status' => 'approved',
            'referral_code_snapshot' => $a->referral_code,
            'first_touch_at' => now()->subDay(),
            'converted_at' => now(),
            'approved_at' => now(),
            'amount_minor' => 500,
            'currency' => 'gbp',
        ]);

        $mail = (new \App\Notifications\AmbassadorConversionApprovedNotification($conv))->toMail($a->user);

        $this->assertSame('Open My Rewards', $mail->actionText);
        $this->assertStringNotContainsStringIgnoringCase('ambassador dashboard', $mail->actionText);
    }

    // ── Internal identifiers preserved ───────────────────────────────────

    public function test_internal_routes_and_backend_identifiers_are_unchanged(): void
    {
        // Route names
        $this->assertTrue(Route::has('ambassador.dashboard'));
        $this->assertTrue(Route::has('ambassador.security'));
        $this->assertTrue(Route::has('ambassador.payout-settings'));
        $this->assertTrue(Route::has('activate'));

        // URIs (route path unchanged; host may vary per environment)
        $this->assertStringEndsWith('/ambassador/dashboard', route('ambassador.dashboard'));

        // Model class + tables
        $this->assertTrue(class_exists(\App\Models\AmbassadorProfile::class));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('ambassador_profiles'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('referral_clicks', 'ambassador_profile_id'));

        // Role enum
        $this->assertSame('ambassador', RoleEnum::Ambassador->value);

        // Notification classes retain their PHP FQNs
        $this->assertTrue(class_exists(AmbassadorWelcomeNotification::class));
        $this->assertTrue(class_exists(\App\Notifications\AmbassadorConversionApprovedNotification::class));
    }

    public function test_no_customer_facing_view_leaks_the_word_ambassador(): void
    {
        // Strip out anything inside HTML attributes (href, src, class, id,
        // action, data-* etc.) before checking — URLs and CSS hooks are
        // internal identifiers that are deliberately left untouched (route
        // paths and testids still say "ambassador"). We only care about the
        // *visible text*.
        $strip = function (string $html): string {
            // remove all attribute values (attr="…" and attr='…')
            $noAttrs = preg_replace('/\s[a-zA-Z_:-]+="[^"]*"/', '', $html) ?? $html;
            $noAttrs = preg_replace("/\s[a-zA-Z_:-]+='[^']*'/", '', $noAttrs) ?? $noAttrs;
            // drop <script>/<style> blocks entirely
            $noAttrs = preg_replace('#<script.*?</script>#si', '', $noAttrs) ?? $noAttrs;
            $noAttrs = preg_replace('#<style.*?</style>#si', '', $noAttrs) ?? $noAttrs;

            return strip_tags($noAttrs);
        };

        $this->assertStringNotContainsStringIgnoringCase('ambassador', $strip($this->get(route('home'))->getContent()));
        $this->assertStringNotContainsStringIgnoringCase('ambassador', $strip($this->get(route('login'))->getContent()));

        $a = $this->activate();
        $body = $this->actingAs($a->user)->get(route('verification.notice'))->getContent();
        $this->assertStringNotContainsStringIgnoringCase('ambassador', $strip($body));
    }
}
