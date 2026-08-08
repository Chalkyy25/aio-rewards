<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Services\AmbassadorActivationService;
use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the mobile responsive rework of the member dashboard: mobile
 * top bar, hamburger, off-canvas drawer with the same nav items as the
 * desktop sidebar, and preserved desktop markup + underlying data.
 */
class MemberDashboardResponsiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
    }

    private function makeVerifiedAmbassador(bool $withPanelRole = false): AmbassadorProfile
    {
        $ambassador = app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'test_active',
            providerPassword: 'letmein',
            email: 'alice@example.com',
            name: 'Alice',
            newPassword: 'newSecret1234',
        ));
        User::query()->whereKey($ambassador->user->id)->update(['email_verified_at' => now()]);
        if ($withPanelRole) {
            $ambassador->user->assignRole(RoleEnum::Admin->value);
        }

        return $ambassador->fresh();
    }

    // ── Layout markup ────────────────────────────────────────────────────

    public function test_dashboard_renders_desktop_sidebar_markup(): void
    {
        $a = $this->makeVerifiedAmbassador();

        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))
            ->assertOk()
            ->getContent();

        // The desktop sidebar (kept for tablet + desktop breakpoints).
        $this->assertStringContainsString('data-testid="member-sidebar"', $body);
        $this->assertStringContainsString('data-testid="nav-dashboard"', $body);
        $this->assertStringContainsString('data-testid="nav-security"', $body);
        $this->assertStringContainsString('data-testid="nav-logout"', $body);
    }

    public function test_dashboard_renders_mobile_topbar_and_hamburger(): void
    {
        $a = $this->makeVerifiedAmbassador();

        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))
            ->getContent();

        $this->assertStringContainsString('data-testid="mobile-topbar"', $body);
        $this->assertStringContainsString('data-testid="mobile-hamburger"', $body);
        // Accessibility hooks.
        $this->assertStringContainsString('aria-controls="member-drawer"', $body);
        $this->assertStringContainsString('aria-label="Open navigation menu"', $body);
        // A brand logo is present in the mobile top bar.
        $this->assertStringContainsString('data-testid="brand-logo-mobile', $body);
    }

    public function test_dashboard_renders_mobile_drawer_with_all_expected_items(): void
    {
        $a = $this->makeVerifiedAmbassador();

        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))
            ->getContent();

        // Drawer root + backdrop.
        $this->assertStringContainsString('id="member-drawer"', $body);
        $this->assertStringContainsString('data-testid="mobile-drawer"', $body);
        $this->assertStringContainsString('data-testid="mobile-drawer-backdrop"', $body);
        $this->assertStringContainsString('data-testid="mobile-drawer-close"', $body);
        // Dialog semantics.
        $this->assertStringContainsString('role="dialog"', $body);
        $this->assertStringContainsString('aria-modal="true"', $body);
        // Nav items — same set as the desktop sidebar.
        $this->assertStringContainsString('data-testid="drawer-nav-dashboard"', $body);
        $this->assertStringContainsString('data-testid="drawer-nav-payout-settings"', $body);
        $this->assertStringContainsString('data-testid="drawer-nav-security"', $body);
        $this->assertStringContainsString('data-testid="drawer-nav-logout"', $body);
        $this->assertStringContainsString('My Rewards', $body);
        $this->assertStringContainsString('Payout Settings', $body);
        $this->assertStringContainsString('Account Security', $body);
        $this->assertStringContainsString('Sign out', $body);
    }

    public function test_drawer_admin_access_link_is_role_gated(): void
    {
        // Without admin/panel role — link absent.
        $a1 = $this->makeVerifiedAmbassador(withPanelRole: false);
        $body1 = $this->actingAs($a1->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();
        $this->assertStringNotContainsString('data-testid="drawer-nav-admin-access"', $body1);
        $this->assertStringNotContainsString('data-testid="nav-admin-access"', $body1);

        // With admin role — both desktop and mobile drawer show it.
        $u = User::factory()->create(['email_verified_at' => now(), 'is_active' => true, 'mfa_enabled' => false]);
        $u->assignRole([RoleEnum::Ambassador->value, RoleEnum::Admin->value]);
        AmbassadorProfile::factory()->create(['user_id' => $u->id]);

        $body2 = $this->actingAs($u->fresh())->get(route('ambassador.dashboard'))->getContent();
        $this->assertStringContainsString('data-testid="drawer-nav-admin-access"', $body2);
        $this->assertStringContainsString('data-testid="nav-admin-access"', $body2);
    }

    public function test_drawer_javascript_and_body_scroll_lock_are_wired(): void
    {
        $a = $this->makeVerifiedAmbassador();
        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();

        // Scroll lock class applied to body when drawer open.
        $this->assertStringContainsString('body.drawer-open', $body);
        // Escape-key handler present.
        $this->assertStringContainsString("e.key === 'Escape'", $body);
        // Global controller so multiple triggers can toggle it.
        $this->assertStringContainsString('window.aioDrawer', $body);
    }

    // ── Referral card + mobile stacked table ─────────────────────────────

    public function test_referral_card_controls_are_present(): void
    {
        $a = $this->makeVerifiedAmbassador();
        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();

        $this->assertStringContainsString('data-testid="referral-link-input"', $body);
        $this->assertStringContainsString('data-testid="copy-referral-link"', $body);
        // Referral code chip still visible.
        $this->assertStringContainsString('data-testid="dash-referral-code"', $body);
    }

    public function test_recent_clicks_has_both_desktop_table_and_mobile_stacked_view(): void
    {
        $a = $this->makeVerifiedAmbassador();
        // Add a click so both markup blocks render.
        \App\Models\ReferralClick::create([
            'ambassador_profile_id' => $a->id,
            'referral_code_snapshot' => $a->referral_code,
            'attribution_id' => bin2hex(random_bytes(8)),
            'ip_hash' => hash('sha256', '1.2.3.4'),
            'user_agent' => 'test',
            'referer_url' => 'https://example.com/somewhere',
            'is_bot' => false,
        ]);

        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();

        $this->assertStringContainsString('data-testid="recent-clicks-table"', $body);
        $this->assertStringContainsString('data-testid="recent-clicks-mobile"', $body);
        // The mobile stacked-view keeps the underlying data (referer URL).
        $this->assertStringContainsString('https://example.com/somewhere', $body);
    }

    public function test_responsive_breakpoints_are_declared(): void
    {
        $a = $this->makeVerifiedAmbassador();
        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();

        // Mobile breakpoint hides the sidebar and shows the top bar.
        $this->assertStringContainsString('@media (max-width: 767px)', $body);
        // Tablet range keeps the sidebar but narrower.
        $this->assertStringContainsString('@media (max-width: 1023px)', $body);
    }

    // ── Data / behaviour preserved ───────────────────────────────────────

    public function test_dashboard_data_is_still_scoped_to_the_signed_in_ambassador(): void
    {
        $a = $this->makeVerifiedAmbassador();

        // Insert a click for a DIFFERENT ambassador — it must not appear.
        $other = AmbassadorProfile::factory()->create();
        \App\Models\ReferralClick::create([
            'ambassador_profile_id' => $other->id,
            'referral_code_snapshot' => $other->referral_code,
            'attribution_id' => 'other-'.bin2hex(random_bytes(4)),
            'ip_hash' => hash('sha256', '9.9.9.9'),
            'user_agent' => 'other',
            'referer_url' => 'https://leak.example',
            'is_bot' => false,
        ]);

        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();

        $this->assertStringNotContainsString('leak.example', $body);
    }

    public function test_signout_form_still_posts_to_logout_route(): void
    {
        $a = $this->makeVerifiedAmbassador();
        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))->getContent();

        // Two forms (sidebar + drawer) both target the logout route.
        $matches = substr_count($body, route('logout'));
        $this->assertGreaterThanOrEqual(2, $matches);
    }
}
