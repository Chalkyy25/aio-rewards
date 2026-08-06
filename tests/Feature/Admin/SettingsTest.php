<?php

namespace Tests\Feature\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Enums\Role as RoleEnum;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\BuyerOrderCompletedNotification;
use App\Notifications\BuyerPaymentReceivedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    public function test_defaults_are_returned_when_no_override_is_set(): void
    {
        $repo = app(SettingsRepository::class);
        $this->assertSame('AIO Rewards', $repo->value('brand.name'));
        $this->assertNotEmpty($repo->value('public.landing_heading'));
    }

    public function test_put_overrides_the_default_and_busts_cache(): void
    {
        $repo = app(SettingsRepository::class);
        $repo->put('brand.name', 'Preview Rewards');

        $this->assertSame('Preview Rewards', app(SettingsRepository::class)->value('brand.name'));
        $this->assertDatabaseHas('settings', ['key' => 'brand.name', 'value' => 'Preview Rewards']);
    }

    public function test_helper_returns_setting_value_or_default(): void
    {
        $this->assertSame('AIO Rewards', settings('brand.name'));

        app(SettingsRepository::class)->put('brand.name', 'Custom Co');
        $this->assertSame('Custom Co', settings('brand.name'));
    }

    public function test_public_pages_render_editable_content(): void
    {
        app(SettingsRepository::class)->putMany([
            'brand.name' => 'Preview Brand',
            'public.packages_heading' => 'Pick a plan today',
            'brand.footer_note' => 'Preview footer.',
        ]);

        Package::factory()->create(['is_active' => true]);

        $this->get('/packages')
            ->assertOk()
            ->assertSee('Preview Brand')
            ->assertSee('Pick a plan today')
            ->assertSee('Preview footer.');

        $this->get('/')
            ->assertOk()
            ->assertSee('Preview Brand');
    }

    public function test_buyer_notifications_use_editable_message_templates(): void
    {
        app(SettingsRepository::class)->putMany([
            'orders.payment_received_lead' => 'CUSTOM PAYMENT LEAD LINE.',
            'orders.completed_lead' => 'CUSTOM COMPLETED LEAD LINE.',
            'brand.support_email' => 'help+custom@example.com',
        ]);

        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'customer_view_token' => str_repeat('x', 32),
            'buyer_email' => 'buyer@example.com',
        ]);

        $paidMail = (new BuyerPaymentReceivedNotification($purchase))->toMail((object) [])->render();
        $this->assertStringContainsString('CUSTOM PAYMENT LEAD LINE.', $paidMail);
        $this->assertStringContainsString('help+custom@example.com', $paidMail);

        $doneMail = (new BuyerOrderCompletedNotification($purchase))->toMail((object) [])->render();
        $this->assertStringContainsString('CUSTOM COMPLETED LEAD LINE.', $doneMail);
    }

    public function test_super_admin_can_access_settings_page(): void
    {
        $sa = User::factory()->create(['is_active' => true]);
        $sa->assignRole(RoleEnum::SuperAdmin->value);

        $this->actingAs($sa);
        $this->assertTrue(\App\Filament\Pages\Settings::canAccess());
    }

    public function test_regular_admin_cannot_access_settings_page(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RoleEnum::Admin->value);

        $this->actingAs($admin);
        $this->assertFalse(\App\Filament\Pages\Settings::canAccess());
    }

    public function test_settings_repository_is_audited_on_write(): void
    {
        app(SettingsRepository::class)->put('brand.name', 'Audited Co');

        $this->assertTrue(
            \App\Models\AuditLog::where('action', 'settings.updated')->exists()
        );
    }
}
