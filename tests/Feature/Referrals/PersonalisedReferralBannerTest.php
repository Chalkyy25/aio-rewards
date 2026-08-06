<?php

namespace Tests\Feature\Referrals;

use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalisedReferralBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_landing_page_personalises_banner_when_ambassador_is_known(): void
    {
        $u = User::factory()->create(['name' => 'Alice Ambassador']);
        AmbassadorProfile::factory()->for($u)->create(['referral_code' => 'ALICE1']);

        $ref = json_encode(['code' => 'ALICE1', 'attribution_id' => 'atr']);
        $cookie = config('referrals.cookie.name', 'aior_ref');

        $this->withUnencryptedCookie($cookie, $ref)
            ->get('/')
            ->assertOk()
            ->assertSee('You were referred by')
            ->assertSee('Alice Ambassador')
            ->assertSee('data-testid="referral-name"', escape: false);
    }

    public function test_landing_page_falls_back_to_generic_banner_when_code_is_unknown(): void
    {
        $ref = json_encode(['code' => 'NOSUCH', 'attribution_id' => 'atr']);
        $cookie = config('referrals.cookie.name', 'aior_ref');

        $this->withUnencryptedCookie($cookie, $ref)
            ->get('/')
            ->assertOk()
            ->assertSee('You were referred by an AIO Rewards member.')
            ->assertDontSee('data-testid="referral-name"', escape: false);
    }

    public function test_landing_page_shows_no_banner_without_cookie(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('data-testid="referral-badge"', escape: false);
    }

    public function test_checkout_review_page_names_the_ambassador(): void
    {
        $u = User::factory()->create(['name' => 'Bob Ambassador']);
        AmbassadorProfile::factory()->for($u)->create(['referral_code' => 'BOB1']);
        $package = Package::factory()->create(['slug' => 'test-pkg', 'is_active' => true]);

        $ref = json_encode(['code' => 'BOB1', 'attribution_id' => 'atr']);
        $cookie = config('referrals.cookie.name', 'aior_ref');

        $this->withUnencryptedCookie($cookie, $ref)
            ->withSession(['checkout.details' => [
                'package_slug' => 'test-pkg',
                'buyer_name' => 'Buyer', 'buyer_email' => 'b@example.com',
                'preferred_username' => 'buyer1', 'delivery_method' => 'email',
                'terms' => '1', 'privacy' => '1',
            ]])
            ->get('/checkout/test-pkg/review')
            ->assertOk()
            ->assertSee('Referral applied')
            ->assertSee('Bob Ambassador');
    }
}
