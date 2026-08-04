<?php

namespace Tests\Feature\Ambassador;

use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmbassadorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dashboard_shows_referral_link_code_status(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'email_verified_at' => now(),
        ]);
        $user->assignRole(RoleEnum::Ambassador->value);
        $profile = AmbassadorProfile::factory()->create([
            'user_id' => $user->id,
            'referral_code' => 'ABCD1234',
        ]);

        $this->actingAs($user)
            ->get(route('ambassador.dashboard'))
            ->assertOk()
            ->assertSee('Alice')
            ->assertSee('ABCD1234')
            ->assertSee($profile->referralUrl())
            ->assertSeeHtml('data-testid="copy-referral-link"')
            ->assertSeeHtml('data-testid="dash-status"')
            ->assertSeeHtml('data-testid="stat-total-clicks"')
            ->assertSeeHtml('data-testid="share-whatsapp"');
    }
}
