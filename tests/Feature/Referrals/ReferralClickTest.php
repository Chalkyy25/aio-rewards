<?php

namespace Tests\Feature\Referrals;

use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\ReferralClick;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralClickTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AmbassadorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->user->assignRole(RoleEnum::Ambassador->value);
        $this->profile = AmbassadorProfile::factory()->create([
            'user_id' => $this->user->id,
            'referral_code' => 'TESTCODE',
        ]);
    }

    public function test_valid_referral_click_records_row_sets_cookie_and_redirects(): void
    {
        $response = $this->withUnencryptedCookies([])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10', 'HTTP_USER_AGENT' => 'Mozilla/5.0'])
            ->get('/r/TESTCODE?utm_source=twitter&utm_medium=social&utm_campaign=launch');

        $response->assertRedirect('/');

        $this->assertDatabaseCount('referral_clicks', 1);
        $click = ReferralClick::first();
        $this->assertSame($this->profile->id, $click->ambassador_profile_id);
        $this->assertSame('TESTCODE', $click->referral_code_snapshot);
        $this->assertSame('twitter', $click->utm_source);
        $this->assertSame('social', $click->utm_medium);
        $this->assertSame('launch', $click->utm_campaign);
        $this->assertFalse($click->is_bot);
        $this->assertNotEmpty($click->attribution_id);

        // IP hashed, not stored raw.
        $this->assertNotEquals('198.51.100.10', $click->ip_hash);
        $this->assertSame(64, strlen($click->ip_hash));
        $this->assertDatabaseMissing('referral_clicks', ['ip_hash' => '198.51.100.10']);

        // Cookie set on the response (encrypted by cookie middleware in real flow).
        $cookies = collect($response->headers->getCookies());
        $this->assertTrue(
            $cookies->contains(fn ($c) => $c->getName() === 'aior_ref'),
            'Expected aior_ref cookie on response'
        );
    }

    public function test_invalid_code_returns_404_and_no_click_row(): void
    {
        $this->get('/r/DOESNTEX')->assertStatus(404)->assertSee('Referral link unavailable');
        $this->assertDatabaseCount('referral_clicks', 0);
    }

    public function test_inactive_ambassador_returns_404_and_no_click_row(): void
    {
        $this->user->update(['is_active' => false]);
        $this->get('/r/TESTCODE')->assertStatus(404);
        $this->assertDatabaseCount('referral_clicks', 0);
    }

    public function test_bot_click_is_recorded_but_flagged(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->get('/r/TESTCODE')
            ->assertRedirect('/');

        $click = ReferralClick::first();
        $this->assertNotNull($click);
        $this->assertTrue($click->is_bot);
    }

    public function test_first_touch_cookie_is_not_overwritten_by_second_ambassador_click(): void
    {
        // First click sets cookie
        $first = $this->get('/r/TESTCODE');
        $first->assertCookie('aior_ref');
        $click = ReferralClick::first();
        $this->assertNotNull($click);

        $firstPayload = json_encode([
            'v' => 1,
            'code' => 'TESTCODE',
            'attribution_id' => $click->attribution_id,
            'set_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        // A second ambassador
        $u2 = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u2->assignRole(RoleEnum::Ambassador->value);
        AmbassadorProfile::factory()->create(['user_id' => $u2->id, 'referral_code' => 'SECOND01']);

        // Send the first-click cookie back on the second visit (encrypted by test client).
        $second = $this->withCookie('aior_ref', $firstPayload)->get('/r/SECOND01');
        $secondCookie = collect($second->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'aior_ref' && $c->getValue() !== '');

        // The controller should NOT set a new cookie because a valid one is present.
        $this->assertNull($secondCookie, 'aior_ref cookie should not be re-set on second click (first-touch wins)');

        // Both clicks still recorded for analytics.
        $this->assertDatabaseCount('referral_clicks', 2);
    }

    public function test_ambassador_sees_only_own_click_data_on_dashboard(): void
    {
        // Foreign ambassador with clicks
        $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $other->assignRole(RoleEnum::Ambassador->value);
        $otherProfile = AmbassadorProfile::factory()->create(['user_id' => $other->id, 'referral_code' => 'OTHER111']);
        ReferralClick::factory()->count(3)->create([
            'ambassador_profile_id' => $otherProfile->id,
            'referer_url' => 'https://secret.example',
        ]);

        // Own clicks: 2 valid + 1 bot
        ReferralClick::factory()->count(2)->create(['ambassador_profile_id' => $this->profile->id]);
        ReferralClick::factory()->bot()->create(['ambassador_profile_id' => $this->profile->id]);

        $response = $this->actingAs($this->user)->get(route('ambassador.dashboard'));
        $response->assertOk();

        // Only own valid clicks (2) counted; bot excluded; foreign clicks excluded.
        $response->assertSeeHtml('data-testid="stat-total-clicks">2<');
        $response->assertDontSee('https://secret.example');
    }

    public function test_ip_rate_limit_returns_429(): void
    {
        config()->set('referrals.click_rate_limits.per_ip_per_min', 2);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->get('/r/TESTCODE')->assertRedirect('/');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->get('/r/TESTCODE')->assertRedirect('/');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->get('/r/TESTCODE')->assertStatus(429);
    }
}
