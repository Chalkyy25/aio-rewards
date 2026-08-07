<?php

namespace App\Domain\Operations;

use App\Domain\Fulfilment\OrderStatus;
use App\Domain\Settings\SettingsRepository;
use App\Enums\OperationsType;
use App\Models\OperationsItem;
use App\Models\Purchase;
use App\Models\ReferralConversion;
use App\Models\Reward;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detects business conditions and turns them into Operations work items.
 * Idempotent: repeat calls upsert against a stable dedupe_key, and clear
 * items whose underlying condition no longer holds.
 *
 * All timing thresholds are read from Settings so admins can tune them
 * without a code change.
 */
class OperationsScanner
{
    public function __construct(
        private readonly OperationsWriter $writer,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Run the full sweep. Returns totals per type + auto-resolved count.
     *
     * @return array{created:int,updated:int,auto_resolved:int,by_type:array<string,int>}
     */
    public function scan(): array
    {
        if ((int) ($this->settings->value('ops.enabled') ?? '1') !== 1) {
            return ['created' => 0, 'updated' => 0, 'auto_resolved' => 0, 'by_type' => []];
        }

        $stats = ['created' => 0, 'updated' => 0, 'auto_resolved' => 0, 'by_type' => []];

        $before = OperationsItem::query()->whereIn('status', \App\Enums\OperationsStatus::openValues())->pluck('id')->all();
        $touched = [];

        foreach ($this->detectors() as $type => $iterable) {
            $type = OperationsType::from($type);
            foreach ($iterable as $spec) {
                $item = $this->writer->upsert($spec);
                $touched[$item->id] = true;
                $stats['by_type'][$type->value] = ($stats['by_type'][$type->value] ?? 0) + 1;
            }
        }

        $stats['auto_resolved'] += $this->autoResolveClearedConditions();

        return $stats;
    }

    /** @return array<string, iterable<OperationsSpec>> */
    private function detectors(): array
    {
        return [
            OperationsType::OrderPaidAwaitingFulfilment->value => $this->detectPaidAwaitingFulfilment(),
            OperationsType::OrderPaidUnviewed->value => $this->detectPaidUnviewed(),
            OperationsType::OrderWaiting15->value => $this->detectOrderWaiting(OperationsType::OrderWaiting15, (int) $this->threshold('ops.order.waiting_l1_minutes', 15)),
            OperationsType::OrderWaiting30->value => $this->detectOrderWaiting(OperationsType::OrderWaiting30, (int) $this->threshold('ops.order.waiting_l2_minutes', 30)),
            OperationsType::OrderWaiting60->value => $this->detectOrderWaiting(OperationsType::OrderWaiting60, (int) $this->threshold('ops.order.waiting_l3_minutes', 60)),
            OperationsType::OrderInProgressTooLong->value => $this->detectOrderInProgressTooLong(),
            OperationsType::OrderCredentialsUnopened->value => $this->detectCompletedCredentialsUnopened(),
            OperationsType::ReferralConversionAwaitingApproval->value => $this->detectConversionAwaitingApproval(),
            OperationsType::RewardAwaitingApproval->value => $this->detectRewardAwaitingApproval(),
            OperationsType::RewardApprovedAwaitingPayment->value => $this->detectApprovedRewardAwaitingPayment(),
            OperationsType::ProviderVerificationFailure->value => $this->detectProviderVerificationFailure(),
            OperationsType::FailedJob->value => $this->detectFailedJobs(),
        ];
    }

    // ── Detectors ────────────────────────────────────────────────────────

    /** @return iterable<OperationsSpec> */
    private function detectPaidAwaitingFulfilment(): iterable
    {
        $q = Purchase::query()
            ->where('status', 'paid')
            ->whereIn('fulfilment_status', [OrderStatus::PaymentReceived->value, OrderStatus::PendingSetup->value])
            ->get();

        foreach ($q as $p) {
            yield new OperationsSpec(
                type: OperationsType::OrderPaidAwaitingFulfilment,
                dedupeKey: 'order.paid_awaiting_fulfilment:'.$p->id,
                title: 'Order #'.$p->id.' awaiting fulfilment',
                summary: 'Paid at '.optional($p->paid_at)->diffForHumans().' — status: '.$p->fulfilment_status,
                subject: $p,
                dueAt: $p->paid_at?->copy()->addMinutes((int) $this->threshold('ops.order.waiting_l1_minutes', 15)),
            );
        }
    }

    /** Paid order not viewed by *any* admin. Reuses first_viewed_at on the ops item as the proxy. */
    /** @return iterable<OperationsSpec> */
    private function detectPaidUnviewed(): iterable
    {
        $unviewedMins = (int) $this->threshold('ops.order.unviewed_minutes', 15);
        $threshold = now()->subMinutes($unviewedMins);

        $q = Purchase::query()
            ->where('status', 'paid')
            ->whereIn('fulfilment_status', [OrderStatus::PaymentReceived->value, OrderStatus::PendingSetup->value])
            ->where('paid_at', '<=', $threshold)
            ->get();

        foreach ($q as $p) {
            // Check whether any operations item for this order has been viewed by an admin.
            $viewed = OperationsItem::query()
                ->where('subject_type', $p->getMorphClass())
                ->where('subject_id', $p->id)
                ->whereNotNull('first_viewed_at')
                ->exists();
            if ($viewed) {
                continue;
            }

            yield new OperationsSpec(
                type: OperationsType::OrderPaidUnviewed,
                dedupeKey: 'order.paid_unviewed:'.$p->id,
                title: 'Order #'.$p->id.' has not been viewed by an admin',
                summary: 'Paid '.$p->paid_at?->diffForHumans().' and nobody has opened it yet.',
                subject: $p,
                meta: ['unviewed_since' => $p->paid_at?->toIso8601String()],
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectOrderWaiting(OperationsType $type, int $minutes): iterable
    {
        $threshold = now()->subMinutes($minutes);
        $q = Purchase::query()
            ->where('status', 'paid')
            ->whereIn('fulfilment_status', [
                OrderStatus::PaymentReceived->value,
                OrderStatus::PendingSetup->value,
                OrderStatus::InProgress->value,
                OrderStatus::AwaitingCustomer->value,
            ])
            ->where('paid_at', '<=', $threshold)
            ->get();

        foreach ($q as $p) {
            $waited = $p->paid_at ? (int) $p->paid_at->diffInMinutes(now()) : $minutes;
            yield new OperationsSpec(
                type: $type,
                dedupeKey: $type->value.':'.$p->id,
                title: 'Order #'.$p->id.' has waited '.$waited.'+ minutes',
                summary: 'Fulfilment status: '.$p->fulfilment_status.'. Threshold: '.$minutes.'m.',
                subject: $p,
                meta: ['waited_minutes' => $waited, 'threshold_minutes' => $minutes],
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectOrderInProgressTooLong(): iterable
    {
        $hours = (int) $this->threshold('ops.order.in_progress_hours', 4);
        $threshold = now()->subHours($hours);

        // "in progress too long" = InProgress AND setup_started_at is present and older than N hours.
        $q = Purchase::query()
            ->where('fulfilment_status', OrderStatus::InProgress->value);
        if (Schema::hasColumn('purchases', 'setup_started_at')) {
            $q->where(function ($qq) use ($threshold) {
                $qq->where('setup_started_at', '<=', $threshold)
                    ->orWhereNull('setup_started_at');
            });
        }

        foreach ($q->get() as $p) {
            $since = $p->setup_started_at ?? $p->paid_at;
            if ($since !== null && $since > $threshold) {
                continue; // fell inside the window between query and check
            }
            yield new OperationsSpec(
                type: OperationsType::OrderInProgressTooLong,
                dedupeKey: 'order.in_progress_too_long:'.$p->id,
                title: 'Order #'.$p->id.' has been In Progress for '.$hours.'+ hours',
                summary: 'Started at '.optional($since)->diffForHumans().'.',
                subject: $p,
                meta: ['threshold_hours' => $hours],
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectCompletedCredentialsUnopened(): iterable
    {
        $hours = (int) $this->threshold('ops.order.credentials_unopened_hours', 24);
        $threshold = now()->subHours($hours);
        $hasViewedAt = Schema::hasColumn('purchases', 'customer_last_viewed_at');

        $q = Purchase::query()
            ->where('fulfilment_status', OrderStatus::Completed->value)
            ->whereNotNull('fulfilled_at')
            ->where('fulfilled_at', '<=', $threshold);

        foreach ($q->get() as $p) {
            if ($hasViewedAt && $p->customer_last_viewed_at !== null) {
                continue;
            }
            yield new OperationsSpec(
                type: OperationsType::OrderCredentialsUnopened,
                dedupeKey: 'order.credentials_unopened:'.$p->id,
                title: 'Order #'.$p->id.' credentials not yet opened by customer',
                summary: 'Completed '.optional($p->fulfilled_at)->diffForHumans().'. Consider a nudge.',
                subject: $p,
                meta: ['threshold_hours' => $hours],
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectConversionAwaitingApproval(): iterable
    {
        $q = ReferralConversion::query()->where('status', 'pending')->get();
        foreach ($q as $c) {
            yield new OperationsSpec(
                type: OperationsType::ReferralConversionAwaitingApproval,
                dedupeKey: 'referrals.conversion_awaiting_approval:'.$c->id,
                title: 'Conversion #'.$c->id.' awaiting approval',
                summary: 'Pending until: '.optional($c->pending_until)->toDayDateTimeString(),
                subject: $c,
                dueAt: $c->pending_until,
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectRewardAwaitingApproval(): iterable
    {
        foreach (Reward::query()->where('status', 'pending_approval')->get() as $r) {
            yield new OperationsSpec(
                type: OperationsType::RewardAwaitingApproval,
                dedupeKey: 'rewards.awaiting_approval:'.$r->id,
                title: 'Reward #'.$r->id.' awaiting approval',
                summary: 'Amount: '.number_format($r->amount_minor / 100, 2).' '.strtoupper($r->currency ?? 'gbp'),
                subject: $r,
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectApprovedRewardAwaitingPayment(): iterable
    {
        $hours = (int) $this->threshold('ops.reward.approved_unpaid_hours', 72);
        $threshold = now()->subHours($hours);

        $q = Reward::query()
            ->with(['ambassadorProfile.payoutProfile'])
            ->where('status', 'approved')
            ->whereNull('paid_at')
            ->where('approved_at', '<=', $threshold)
            ->get();

        foreach ($q as $r) {
            $configured = (bool) $r->ambassadorProfile?->hasConfiguredPayoutMethod();
            $method = $r->ambassadorProfile?->payoutProfile?->preferred_method?->value;

            yield new OperationsSpec(
                type: OperationsType::RewardApprovedAwaitingPayment,
                dedupeKey: 'rewards.approved_awaiting_payment:'.$r->id,
                title: 'Reward #'.$r->id.' approved but unpaid for '.$hours.'+ hours',
                summary: 'Approved '.optional($r->approved_at)->diffForHumans().'. Please pay out.',
                subject: $r,
                // Safe operational flags only — never bank/PayPal secrets.
                meta: [
                    'threshold_hours' => $hours,
                    'payout_configured' => $configured,
                    'preferred_payout_method' => $method,
                ],
            );
        }
    }

    /** @return iterable<OperationsSpec> */
    private function detectProviderVerificationFailure(): iterable
    {
        $lastFail = $this->settings->value('provider.last_failure_at');
        $lastOk = $this->settings->value('provider.last_success_at');
        if (! $lastFail) {
            return;
        }
        $failAt = Carbon::parse($lastFail);
        $okAt = $lastOk ? Carbon::parse($lastOk) : null;
        if ($okAt && $okAt->gte($failAt)) {
            return; // recovered
        }

        // Ignore failures older than 24 hours to avoid dragging historical
        // alarms forward each scan.
        if ($failAt->lt(now()->subDay())) {
            return;
        }

        yield new OperationsSpec(
            type: OperationsType::ProviderVerificationFailure,
            dedupeKey: 'provider.verification_failure:'.$failAt->timestamp,
            title: 'Provider verification failing',
            summary: 'Last failure '.$failAt->diffForHumans().'. Code: '.($this->settings->value('provider.last_response_code') ?? '—').' — '.($this->settings->value('provider.last_note') ?? '—'),
            meta: [
                'last_failure_at' => $failAt->toIso8601String(),
                'last_response_code' => $this->settings->value('provider.last_response_code'),
                'last_note' => $this->settings->value('provider.last_note'),
            ],
        );
    }

    /** @return iterable<OperationsSpec> */
    private function detectFailedJobs(): iterable
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get();
        foreach ($rows as $row) {
            $isNotification = str_contains((string) $row->payload, 'Notification');
            $type = $isNotification ? OperationsType::FailedNotification : OperationsType::FailedJob;
            yield new OperationsSpec(
                type: $type,
                dedupeKey: $type->value.':'.$row->uuid,
                title: ($isNotification ? 'Failed notification job ' : 'Failed job ').$row->uuid,
                summary: substr((string) ($row->exception ?? ''), 0, 500),
                meta: ['queue' => $row->queue ?? null, 'connection' => $row->connection ?? null],
            );
        }
    }

    // ── Auto-resolve ─────────────────────────────────────────────────────

    /**
     * Close items whose underlying condition has cleared. Cheap SELECTs
     * against domain tables to look up the current state.
     */
    private function autoResolveClearedConditions(): int
    {
        $closed = 0;

        // 1) Order-lifecycle items: any Purchase that is Completed/Cancelled/Refunded should not have open order.* items.
        $open = OperationsItem::query()
            ->whereIn('status', \App\Enums\OperationsStatus::openValues())
            ->where('subject_type', (new Purchase)->getMorphClass())
            ->where(function ($q) {
                $q->where('type', 'like', 'order.%');
            })
            ->get();

        foreach ($open as $item) {
            $p = Purchase::find($item->subject_id);
            if ($p === null) {
                $closed += $this->writer->autoResolve($item->dedupe_key, 'purchase deleted');

                continue;
            }
            $terminal = in_array($p->fulfilment_status, [
                OrderStatus::Completed->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
            ], true);
            // Special-case: OrderCredentialsUnopened only clears when customer actually opened.
            if ($item->type === OperationsType::OrderCredentialsUnopened->value) {
                if (Schema::hasColumn('purchases', 'customer_last_viewed_at') && $p->customer_last_viewed_at !== null) {
                    $closed += $this->writer->autoResolve($item->dedupe_key, 'customer opened credentials');
                }

                continue;
            }
            if ($terminal) {
                $closed += $this->writer->autoResolve($item->dedupe_key, 'order status: '.$p->fulfilment_status);
            }
        }

        // 2) Referral conversion approved/reversed → clear its item.
        $convItems = OperationsItem::query()
            ->whereIn('status', \App\Enums\OperationsStatus::openValues())
            ->where('subject_type', (new ReferralConversion)->getMorphClass())
            ->where('type', OperationsType::ReferralConversionAwaitingApproval->value)
            ->get();
        foreach ($convItems as $item) {
            $c = ReferralConversion::find($item->subject_id);
            if ($c === null || $c->status !== 'pending') {
                $closed += $this->writer->autoResolve($item->dedupe_key, 'conversion status: '.($c->status ?? 'deleted'));
            }
        }

        // 3) Rewards: awaiting_approval clears when status leaves pending_approval;
        //    approved_awaiting_payment clears when paid_at is set.
        $rewardItems = OperationsItem::query()
            ->whereIn('status', \App\Enums\OperationsStatus::openValues())
            ->where('subject_type', (new Reward)->getMorphClass())
            ->get();
        foreach ($rewardItems as $item) {
            $r = Reward::find($item->subject_id);
            if ($r === null) {
                $closed += $this->writer->autoResolve($item->dedupe_key, 'reward deleted');

                continue;
            }
            if ($item->type === OperationsType::RewardAwaitingApproval->value && $r->status !== 'pending_approval') {
                $closed += $this->writer->autoResolve($item->dedupe_key, 'reward status: '.$r->status);
            }
            if ($item->type === OperationsType::RewardApprovedAwaitingPayment->value && $r->paid_at !== null) {
                $closed += $this->writer->autoResolve($item->dedupe_key, 'reward paid');
            }
        }

        return $closed;
    }

    private function threshold(string $key, int $default): int
    {
        $v = $this->settings->value($key);

        return $v === null || $v === '' ? $default : (int) $v;
    }
}
