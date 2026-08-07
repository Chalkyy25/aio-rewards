<?php

namespace Tests\Feature\Branding;

use App\Domain\Ambassadors\DTOs\ActivationInput;
use App\Domain\Ambassadors\Services\AmbassadorActivationService;
use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(CustomerVerificationContract::class, new FakeVerificationDriver);
    }

    public function test_logo_assets_are_present_in_public_directory(): void
    {
        $this->assertFileExists(public_path('images/aio-media-logo-light.png'));
        $this->assertFileExists(public_path('images/aio-media-logo-dark.png'));
    }

    public function test_public_landing_renders_class_based_theme_swap_logo(): void
    {
        $body = $this->get(route('home'))->assertOk()->getContent();

        // Both variants are rendered; CSS shows one at a time based on the
        // `.dark` class (Filament's theme-toggle signal) — NOT prefers-color-scheme.
        $this->assertStringContainsString('images/aio-media-logo-light.png', $body);
        $this->assertStringContainsString('images/aio-media-logo-dark.png', $body);
        $this->assertStringContainsString('data-testid="brand-logo-public-img-light"', $body);
        $this->assertStringContainsString('data-testid="brand-logo-public-img-dark"', $body);
        // The CSS rule that drives the swap is present.
        $this->assertStringContainsString('html.dark .aio-logo .aio-logo__light', $body);
        // We must NOT be relying on OS preference any more.
        $this->assertStringNotContainsString('prefers-color-scheme', $body);
    }

    public function test_public_layouts_do_not_declare_color_scheme_dark(): void
    {
        $body = $this->get(route('home'))->getContent();
        // color-scheme must be plain "light" — advertising "light dark" was
        // what made native controls / bgs turn black in dark-mode browsers.
        $this->assertMatchesRegularExpression('/color-scheme:\s*light\s*;/', $body);
        $this->assertDoesNotMatchRegularExpression('/color-scheme:\s*light\s+dark/', $body);
    }

    public function test_member_layout_uses_the_dark_theme_logo_for_the_dark_sidebar(): void
    {
        $a = app(AmbassadorActivationService::class)->activate(new ActivationInput(
            providerUsername: 'test_active',
            providerPassword: 'letmein',
            email: 'alice@example.com',
            name: 'Alice',
            newPassword: 'newSecret1234',
        ));
        User::query()->whereKey($a->user->id)->update(['email_verified_at' => now()]);

        $body = $this->actingAs($a->user->refresh())
            ->get(route('ambassador.dashboard'))
            ->assertOk()
            ->getContent();

        // Sidebar sits on #0f172a → always the white-text (dark-theme) logo.
        $this->assertStringContainsString('images/aio-media-logo-dark.png', $body);
        $this->assertStringContainsString('data-testid="brand-logo-member-img"', $body);
    }

    public function test_favicon_link_points_at_the_aio_mark(): void
    {
        $body = $this->get(route('home'))->getContent();
        $this->assertMatchesRegularExpression('#<link[^>]+rel="icon"[^>]+aio-favicon\.png#', $body);
        $this->assertMatchesRegularExpression('#<link[^>]+rel="apple-touch-icon"[^>]+aio-favicon\.png#', $body);
    }

    public function test_favicon_ico_is_served_with_png_content_type(): void
    {
        // Browsers auto-fetch /favicon.ico on every page load and validate
        // magic bytes against the Content-Type. Serving our PNG bytes under
        // that URL must therefore advertise image/png, not the extension-
        // guessed image/vnd.microsoft.icon that the static file server used
        // to return — that mismatch made Chrome / Firefox reject the icon.
        $r = $this->get('/favicon.ico');
        $r->assertOk();
        $r->assertHeader('Content-Type', 'image/png');

        // response()->file() returns a BinaryFileResponse whose body is not
        // populated until sending; assert against the underlying file
        // instead of getContent().
        $sent = $r->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? $r->baseResponse->getFile()->getPathname()
            : null;
        $this->assertNotNull($sent, 'Expected a BinaryFileResponse for /favicon.ico');
        $bytes = file_get_contents($sent);
        $this->assertGreaterThan(1000, strlen($bytes));
        // First two bytes of any PNG file are 0x89 0x50 ('PNG' signature).
        $this->assertSame("\x89P", substr($bytes, 0, 2));
    }

    public function test_filament_admin_panel_registers_brand_logos(): void
    {
        /** @var \Filament\Panel $panel */
        $panel = filament()->getPanel('admin');

        $light = value($panel->getBrandLogo());
        $dark = value($panel->getDarkModeBrandLogo());

        $this->assertNotNull($light);
        $this->assertNotNull($dark);
        $this->assertStringContainsString('aio-media-logo-light.png', (string) $light);
        $this->assertStringContainsString('aio-media-logo-dark.png', (string) $dark);
    }
}
