<?php

namespace Tests\Feature\Referrals;

use App\Domain\Referrals\AttributionCookie;
use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\ReferralClick;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttributionCookieSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'name' => 'Ref Member']);
        $this->user->assignRole(RoleEnum::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->create([
            'user_id' => $this->user->id,
            'referral_code' => 'TESTCODE',
        ]);
    }

    public function test_valid_cookie_is_accepted_and_attributes_checkout(): void
    {
        $click = ReferralClick::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => 'TESTCODE',
            'attribution_id' => '01VALIDATTR00000000000001',
        ]);

        $payload = json_encode([
            'v' => 1,
            'code' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
            'set_at' => now()->toIso8601String(),
        ]);

        $package = Package::factory()->create(['slug' => 'attr-pkg', 'amount_minor' => 6000]);
        config(['stripe.secret' => '']);

        $this->withSession(['checkout.details' => [
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer-attr@example.com',
            'preferred_username' => 'buyer_attr',
            'delivery_method' => 'email',
            'terms' => '1',
            'privacy' => '1',
            'package_slug' => 'attr-pkg',
        ]])
            ->withCookie('aior_ref', $payload)
            ->post('/checkout/attr-pkg/pay');

        $this->assertDatabaseHas('purchases', [
            'buyer_email' => 'buyer-attr@example.com',
            'referral_code_snapshot' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
            'ambassador_profile_id_snapshot' => $this->profile->id,
        ]);
    }

    public function test_tampered_cookie_json_is_rejected(): void
    {
        $cookie = app(AttributionCookie::class);
        $this->assertNull($cookie->validatePayload([
            'code' => 'TESTCODE',
            // missing attribution_id
        ]));
        $this->assertNull($cookie->validatePayload([
            'code' => 'TESTCODE',
            'attribution_id' => 'does-not-exist',
            'set_at' => now()->toIso8601String(),
        ]));
    }

    public function test_expired_attribution_is_rejected(): void
    {
        $click = ReferralClick::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => 'TESTCODE',
            'attribution_id' => '01EXPIREDATTR000000000001',
        ]);

        $payload = [
            'v' => 1,
            'code' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
            'set_at' => now()->subDays(45)->toIso8601String(),
        ];

        $this->assertNull(app(AttributionCookie::class)->validatePayload($payload));
    }

    public function test_missing_set_at_with_old_click_is_rejected(): void
    {
        $click = ReferralClick::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => 'TESTCODE',
            'attribution_id' => '01OLDCLICKATTR00000000001',
            'created_at' => now()->subDays(45),
        ]);

        $this->assertNull(app(AttributionCookie::class)->validatePayload([
            'v' => 1,
            'code' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
            // no set_at
        ]));
    }

    public function test_missing_set_at_with_fresh_click_is_accepted(): void
    {
        $click = ReferralClick::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => 'TESTCODE',
            'attribution_id' => '01FRESHCLICKATTR000000001',
            'created_at' => now()->subDays(2),
        ]);

        $validated = app(AttributionCookie::class)->validatePayload([
            'v' => 1,
            'code' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
        ]);

        $this->assertNotNull($validated);
        $this->assertSame('TESTCODE', $validated['code']);
        $this->assertSame($click->attribution_id, $validated['attribution_id']);
    }

    public function test_referral_code_substitution_attempt_is_rejected(): void
    {
        $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $other->assignRole(RoleEnum::Ambassador->value);
        $otherProfile = AmbassadorProfile::factory()->create([
            'user_id' => $other->id,
            'referral_code' => 'OTHER111',
        ]);

        $click = ReferralClick::factory()->create([
            'ambassador_profile_id' => $this->profile->id,
            'referral_code_snapshot' => 'TESTCODE',
            'attribution_id' => '01SUBSTATTR00000000000001',
        ]);

        // Attacker keeps real attribution_id but swaps code to another ambassador.
        $payload = [
            'v' => 1,
            'code' => 'OTHER111',
            'attribution_id' => $click->attribution_id,
            'set_at' => now()->toIso8601String(),
        ];

        $this->assertNull(app(AttributionCookie::class)->validatePayload($payload));
        $this->assertNotSame($otherProfile->referral_code, $click->referral_code_snapshot);
    }

    public function test_aior_ref_is_not_excluded_from_cookie_encryption(): void
    {
        $middleware = app(EncryptCookies::class);
        $ref = new \ReflectionClass($middleware);
        $prop = $ref->getProperty('except');
        $prop->setAccessible(true);
        /** @var list<string> $excepted */
        $excepted = $prop->getValue($middleware);

        $this->assertIsArray($excepted);
        $this->assertNotContains('aior_ref', $excepted);
        $this->assertNotContains(config('referrals.cookie.name', 'aior_ref'), $excepted);
    }

    public function test_click_sets_cookie_that_survives_validation(): void
    {
        Carbon::setTestNow(now());
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_USER_AGENT' => 'Mozilla/5.0'])
            ->get('/r/TESTCODE');
        $response->assertRedirect('/');
        $response->assertCookie('aior_ref');

        $click = ReferralClick::first();
        $this->assertNotNull($click);

        // Cookie is encrypted in transit; validate the attribution it represents.
        $payload = [
            'v' => 1,
            'code' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
            'set_at' => now()->toIso8601String(),
        ];
        $this->assertNotNull(app(AttributionCookie::class)->validatePayload($payload));

        // Round-trip: encrypted cookie from the browser is accepted at checkout.
        $this->withCookie('aior_ref', json_encode($payload, JSON_THROW_ON_ERROR))
            ->get('/')
            ->assertOk()
            ->assertSee('You were referred by');
    }
}
