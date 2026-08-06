<?php

namespace Tests\Feature\Fulfilment;

use App\Domain\Referrals\ConversionService;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_create_pending_from_purchase_only_when_paid_and_referred(): void
    {
        $amb = AmbassadorProfile::factory()->create();
        $package = Package::factory()->create();

        // Not referred → no conversion.
        $p1 = Purchase::factory()->create(['package_id' => $package->id, 'status' => 'paid']);
        $this->assertNull(app(ConversionService::class)->createPendingFromPurchase($p1));

        // Referred but pending payment → no conversion.
        $p2 = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'pending',
            'ambassador_profile_id_snapshot' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
        ]);
        $this->assertNull(app(ConversionService::class)->createPendingFromPurchase($p2));

        // Paid & referred → conversion created.
        $p3 = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'ambassador_profile_id_snapshot' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
        ]);
        $conv = app(ConversionService::class)->createPendingFromPurchase($p3);
        $this->assertNotNull($conv);
        $this->assertSame('pending', $conv->status);
    }

    public function test_create_pending_is_idempotent(): void
    {
        $amb = AmbassadorProfile::factory()->create();
        $package = Package::factory()->create();
        $purchase = Purchase::factory()->create([
            'package_id' => $package->id,
            'status' => 'paid',
            'ambassador_profile_id_snapshot' => $amb->id,
            'referral_code_snapshot' => $amb->referral_code,
        ]);

        $first = app(ConversionService::class)->createPendingFromPurchase($purchase);
        $second = app(ConversionService::class)->createPendingFromPurchase($purchase);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReferralConversion::where('purchase_id', $purchase->id)->count());
    }

    public function test_approve_transitions_pending_to_approved(): void
    {
        $conv = ReferralConversion::factory()->create(['status' => 'pending']);
        $actor = User::factory()->create();

        $this->assertTrue(app(ConversionService::class)->approve($conv, $actor));
        $conv->refresh();
        $this->assertSame('approved', $conv->status);
        $this->assertSame($actor->id, $conv->approved_by_user_id);
        $this->assertNotNull($conv->approved_at);
    }

    public function test_approve_is_noop_when_not_pending(): void
    {
        $conv = ReferralConversion::factory()->create(['status' => 'approved']);
        $this->assertFalse(app(ConversionService::class)->approve($conv));
    }

    public function test_reverse_transitions_and_records_reason(): void
    {
        $conv = ReferralConversion::factory()->create(['status' => 'pending']);

        $this->assertTrue(app(ConversionService::class)->reverse($conv, 'refund'));
        $conv->refresh();
        $this->assertSame('reversed', $conv->status);
        $this->assertSame('refund', $conv->reversed_reason);
    }

    public function test_ripe_helper_flags_pending_past_window(): void
    {
        $ripe = ReferralConversion::factory()->create([
            'status' => 'pending',
            'pending_until' => now()->subDay(),
        ]);
        $young = ReferralConversion::factory()->create([
            'status' => 'pending',
            'pending_until' => now()->addDay(),
        ]);

        $this->assertTrue($ripe->isRipeForApproval());
        $this->assertFalse($young->isRipeForApproval());
    }
}
