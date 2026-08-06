<?php

namespace Tests\Feature\Operations;

use App\Domain\Fulfilment\OrderStatus;
use App\Domain\Operations\OperationsScanner;
use App\Domain\Operations\OperationsWriter;
use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Enums\OperationsType;
use App\Models\AmbassadorProfile;
use App\Models\OperationsItem;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_detects_paid_order_awaiting_fulfilment_and_dedupes_on_second_run(): void
    {
        $p = $this->makePurchase(fulfilment: OrderStatus::PaymentReceived, paidAt: now()->subMinutes(2));

        app(OperationsScanner::class)->scan();
        app(OperationsScanner::class)->scan(); // second pass must NOT duplicate

        $this->assertDatabaseCount('operations_items', 1);
        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::OrderPaidAwaitingFulfilment->value,
            'subject_id' => $p->id,
            'status' => OperationsStatus::New->value,
        ]);
    }

    public function test_paid_order_waiting_15_30_60_ladder_fires_at_configured_thresholds(): void
    {
        settings()->putMany([
            'ops.order.waiting_l1_minutes' => '15',
            'ops.order.waiting_l2_minutes' => '30',
            'ops.order.waiting_l3_minutes' => '60',
        ]);
        $p = $this->makePurchase(fulfilment: OrderStatus::PaymentReceived, paidAt: now()->subMinutes(65));

        app(OperationsScanner::class)->scan();

        foreach ([
            OperationsType::OrderWaiting15,
            OperationsType::OrderWaiting30,
            OperationsType::OrderWaiting60,
        ] as $type) {
            $this->assertDatabaseHas('operations_items', ['type' => $type->value, 'subject_id' => $p->id]);
        }
    }

    public function test_auto_resolves_when_order_reaches_completed(): void
    {
        $p = $this->makePurchase(fulfilment: OrderStatus::PaymentReceived, paidAt: now()->subMinutes(2));
        app(OperationsScanner::class)->scan();

        $p->fulfilment_status = OrderStatus::Completed->value;
        $p->fulfilled_at = now();
        $p->save();

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseMissing('operations_items', [
            'type' => OperationsType::OrderPaidAwaitingFulfilment->value,
            'subject_id' => $p->id,
            'status' => OperationsStatus::New->value,
        ]);
        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::OrderPaidAwaitingFulfilment->value,
            'subject_id' => $p->id,
            'status' => OperationsStatus::Resolved->value,
        ]);
    }

    public function test_detects_referral_conversion_awaiting_approval(): void
    {
        $ambassador = $this->makeAmbassador();
        $p = $this->makePurchase(fulfilment: OrderStatus::Completed, paidAt: now()->subDay());
        $c = ReferralConversion::create([
            'purchase_id' => $p->id,
            'ambassador_profile_id' => $ambassador->id,
            'status' => 'pending',
            'pending_until' => now()->addDay(),
            'referral_code_snapshot' => $ambassador->referral_code,
            'first_touch_at' => now()->subDay(),
            'converted_at' => now()->subHour(),
            'amount_minor' => 6000,
            'currency' => 'gbp',
        ]);

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::ReferralConversionAwaitingApproval->value,
            'subject_id' => $c->id,
        ]);
    }

    public function test_detects_reward_awaiting_and_approved_unpaid(): void
    {
        settings()->put('ops.reward.approved_unpaid_hours', '1');
        $ambassador = $this->makeAmbassador();
        $rule = RewardRule::create([
            'name' => 'First 5', 'trigger_type' => 'referral_count', 'threshold' => 5,
            'amount_minor' => 5000, 'currency' => 'gbp', 'is_active' => true,
        ]);
        $pending = Reward::factory()->create([
            'ambassador_profile_id' => $ambassador->id, 'reward_rule_id' => $rule->id, 'milestone_index' => 1,
            'status' => 'pending_approval',
        ]);
        $approved = Reward::factory()->create([
            'ambassador_profile_id' => $ambassador->id, 'reward_rule_id' => $rule->id, 'milestone_index' => 2,
            'status' => 'approved', 'approved_at' => now()->subHours(3),
        ]);

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', ['type' => OperationsType::RewardAwaitingApproval->value, 'subject_id' => $pending->id]);
        $this->assertDatabaseHas('operations_items', ['type' => OperationsType::RewardApprovedAwaitingPayment->value, 'subject_id' => $approved->id]);
    }

    public function test_provider_verification_failure_becomes_critical_item(): void
    {
        settings()->putMany([
            'provider.last_failure_at' => now()->subMinutes(10)->toIso8601String(),
            'provider.last_response_code' => '520',
            'provider.last_note' => 'upstream_5xx',
        ]);

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseHas('operations_items', [
            'type' => OperationsType::ProviderVerificationFailure->value,
            'priority' => OperationsPriority::Critical->value,
        ]);
    }

    public function test_scanner_disabled_when_setting_is_off(): void
    {
        $this->makePurchase(fulfilment: OrderStatus::PaymentReceived, paidAt: now()->subMinutes(2));
        settings()->put('ops.enabled', '0');

        app(OperationsScanner::class)->scan();

        $this->assertDatabaseCount('operations_items', 0);
    }

    public function test_writer_records_audit_events_on_create_and_resolve(): void
    {
        $p = $this->makePurchase(fulfilment: OrderStatus::PaymentReceived, paidAt: now()->subMinutes(2));
        app(OperationsScanner::class)->scan();
        $item = OperationsItem::query()->firstOrFail();

        $actor = User::factory()->create();
        app(OperationsWriter::class)->markSeen($item, $actor);
        app(OperationsWriter::class)->resolve($item->refresh(), 'Fulfilled manually', $actor);

        $this->assertDatabaseHas('operations_item_events', ['operations_item_id' => $item->id, 'action' => 'created']);
        $this->assertDatabaseHas('operations_item_events', ['operations_item_id' => $item->id, 'action' => 'seen', 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('operations_item_events', ['operations_item_id' => $item->id, 'action' => 'resolved', 'actor_user_id' => $actor->id]);
        $this->assertSame(OperationsStatus::Resolved->value, $item->refresh()->status);
        $this->assertSame('Fulfilled manually', $item->resolution_notes);
    }

    private function makePurchase(OrderStatus $fulfilment, ?\DateTimeInterface $paidAt = null): Purchase
    {
        return Purchase::factory()->paid()->create([
            'fulfilment_status' => $fulfilment->value,
            'paid_at' => $paidAt ?? now(),
        ]);
    }

    private function makeAmbassador(): AmbassadorProfile
    {
        return AmbassadorProfile::factory()->create();
    }
}
