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

    public function test_public_landing_serves_the_responsive_logo(): void
    {
        $body = $this->get(route('home'))->assertOk()->getContent();

        // <picture> element renders both variants so the browser can pick
        // according to prefers-color-scheme.
        $this->assertStringContainsString('images/aio-media-logo-light.png', $body);
        $this->assertStringContainsString('images/aio-media-logo-dark.png', $body);
        $this->assertStringContainsString('prefers-color-scheme: dark', $body);
        $this->assertStringContainsString('data-testid="brand-logo-public-picture"', $body);
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

    public function test_favicon_link_points_at_the_light_logo(): void
    {
        $body = $this->get(route('home'))->getContent();
        $this->assertMatchesRegularExpression('#<link[^>]+rel="icon"[^>]+aio-media-logo-light\.png#', $body);
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
