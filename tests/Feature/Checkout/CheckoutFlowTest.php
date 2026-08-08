<?php

namespace Tests\Feature\Checkout;

use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralClick;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->package = Package::factory()->create([
            'slug' => 'test-package',
            'amount_minor' => 6000,
        ]);
    }

    public function test_packages_page_lists_active_packages(): void
    {
        Package::factory()->create(['name' => 'Inactive One', 'is_active' => false]);

        $this->get('/packages')
            ->assertOk()
            ->assertSee($this->package->name)
            ->assertDontSee('Inactive One');
    }

    public function test_details_page_renders_for_active_package(): void
    {
        $this->get('/checkout/test-package/details')
            ->assertOk()
            ->assertSee($this->package->name)
            ->assertSee('Full name');
    }

    public function test_details_page_returns_404_for_inactive_slug(): void
    {
        Package::factory()->create(['slug' => 'not-live', 'is_active' => false]);

        $this->get('/checkout/not-live/details')->assertNotFound();
    }

    public function test_details_form_validates_required_fields(): void
    {
        $this->from('/checkout/test-package/details')
            ->post('/checkout/test-package/details', [])
            ->assertRedirect('/checkout/test-package/details')
            ->assertSessionHasErrors(['buyer_name', 'buyer_email', 'preferred_username', 'terms', 'privacy']);
    }

    public function test_details_form_rejects_invalid_username_characters(): void
    {
        $payload = $this->validDetailsPayload(['preferred_username' => 'has spaces!']);

        $this->from('/checkout/test-package/details')
            ->post('/checkout/test-package/details', $payload)
            ->assertRedirect('/checkout/test-package/details')
            ->assertSessionHasErrors(['preferred_username']);
    }

    public function test_details_form_requires_phone_when_delivery_is_whatsapp(): void
    {
        $payload = $this->validDetailsPayload(['delivery_method' => 'whatsapp', 'buyer_phone' => null]);

        $this->from('/checkout/test-package/details')
            ->post('/checkout/test-package/details', $payload)
            ->assertRedirect('/checkout/test-package/details')
            ->assertSessionHasErrors(['buyer_phone']);
    }

    public function test_details_form_persists_session_and_redirects_to_review(): void
    {
        $payload = $this->validDetailsPayload();

        $this->post('/checkout/test-package/details', $payload)
            ->assertRedirect('/checkout/test-package/review');

        $stored = session('checkout.details');
        $this->assertSame($payload['buyer_email'], $stored['buyer_email']);
        $this->assertSame('test-package', $stored['package_slug']);
    }

    public function test_review_redirects_to_details_when_session_missing(): void
    {
        $this->get('/checkout/test-package/review')
            ->assertRedirect('/checkout/test-package/details');
    }

    public function test_review_renders_summary_when_session_present(): void
    {
        $this->withSession(['checkout.details' => $this->validDetailsPayload([
            'package_slug' => 'test-package',
        ])])
            ->get('/checkout/test-package/review')
            ->assertOk()
            ->assertSee('Review your order')
            ->assertSee('£60.00');
    }

    public function test_pay_returns_error_when_stripe_unconfigured(): void
    {
        config(['stripe.secret' => '']);

        $this->withSession(['checkout.details' => $this->validDetailsPayload(['package_slug' => 'test-package'])])
            ->post('/checkout/test-package/pay')
            ->assertRedirect('/checkout/test-package/review')
            ->assertSessionHasErrors(['stripe']);
    }

    public function test_pay_creates_purchase_with_referral_attribution_from_cookie(): void
    {
        config(['stripe.secret' => '']); // avoid live API call

        $ambassador = AmbassadorProfile::factory()->create(['referral_code' => 'PREVIEW1']);
        $click = ReferralClick::factory()->create([
            'ambassador_profile_id' => $ambassador->id,
            'referral_code_snapshot' => 'PREVIEW1',
            'attribution_id' => '01H000000000000000000ATTR1',
        ]);

        $ref = json_encode([
            'v' => 1,
            'code' => 'PREVIEW1',
            'attribution_id' => $click->attribution_id,
            'set_at' => now()->toIso8601String(),
        ]);

        $this->withSession(['checkout.details' => $this->validDetailsPayload([
            'package_slug' => 'test-package',
            'buyer_email' => 'buyer@example.com',
        ])])
            ->withCookie(config('referrals.cookie.name', 'aior_ref'), $ref)
            ->post('/checkout/test-package/pay');

        $this->assertDatabaseHas('purchases', [
            'buyer_email' => 'buyer@example.com',
            'referral_code_snapshot' => 'PREVIEW1',
            'attribution_id' => '01H000000000000000000ATTR1',
            'ambassador_profile_id_snapshot' => $ambassador->id,
            'status' => 'pending',
        ]);
    }

    public function test_success_page_renders_with_purchase(): void
    {
        $purchase = Purchase::factory()->create([
            'package_id' => $this->package->id,
            'stripe_session_id' => 'cs_test_success_1',
            'status' => 'pending',
        ]);

        $this->get('/checkout/success?session_id=cs_test_success_1')
            ->assertOk()
            ->assertSee($purchase->orderReference())
            ->assertSee($this->package->name);
    }

    public function test_cancel_page_renders(): void
    {
        $purchase = Purchase::factory()->create(['package_id' => $this->package->id]);

        $this->get('/checkout/cancel?purchase='.$purchase->id)
            ->assertOk()
            ->assertSee('Payment was not completed');
    }

    /** @return array<string,mixed> */
    private function validDetailsPayload(array $overrides = []): array
    {
        return array_merge([
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'buyer@example.com',
            'preferred_username' => 'test_buyer_1',
            'delivery_method' => 'email',
            'buyer_phone' => '+441234567890',
            'buyer_telegram' => null,
            'terms' => '1',
            'privacy' => '1',
        ], $overrides);
    }
}
