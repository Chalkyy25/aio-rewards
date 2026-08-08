@extends('layouts.ambassador')

@section('title', 'Reward Milestones')

@php
    /** @var \App\Domain\Rewards\MilestoneProgress $progress */
    /** @var \App\Models\AmbassadorProfile $profile */
    /** @var array<string,int> $summary */
    /** @var ?\App\Enums\PayoutMethod $claimMethod */
    /** @var bool $canClaim */
    $flashError = session('milestone_error');
    $flashClaimed = session('milestone_claimed');
    $eligible = $progress->eligibleCount;
    $available = $progress->availableTier;
    $next = $progress->nextTier;
    $isAccountCredit = $claimMethod === \App\Enums\PayoutMethod::AccountCredit;
@endphp

@push('head')
<style>
    h1.page-h1 { font-size: 1.8rem; margin: 0 0 .25rem; }
    .lede { color: #475569; margin: 0 0 1.5rem; }

    .flash { padding: .75rem 1rem; border-radius: .75rem; margin-bottom: 1rem;
             font-size: .95rem; }
    .flash.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .flash.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .flash.info { background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; }

    .summary-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: .75rem;
                    margin-bottom: 1.5rem; }
    .summary-card { background: #fff; border-radius: .75rem; padding: 1rem;
                    box-shadow: 0 1px 3px rgba(15,23,42,.06); }
    .summary-card h3 { margin: 0; font-size: .7rem; text-transform: uppercase;
                       letter-spacing: .08em; color: #64748b; }
    .summary-card .v { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: .25rem; }

    .journey-hero { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                    color: #f8fafc; border-radius: 1rem; padding: 1.75rem;
                    box-shadow: 0 8px 24px rgba(15,23,42,.18); margin-bottom: 1.5rem; }
    .journey-hero .kicker { font-size: .75rem; text-transform: uppercase;
                            letter-spacing: .12em; color: #94a3b8; margin: 0 0 .35rem; }
    .journey-hero h2 { margin: 0 0 .5rem; font-size: 1.6rem; }
    .journey-hero p { margin: 0 0 1rem; color: #cbd5e1; }
    .bar { background: rgba(148,163,184,.25); border-radius: 999px;
           height: 12px; overflow: hidden; }
    .bar > div { height: 100%; background: linear-gradient(90deg, #22c55e, #10b981);
                 transition: width .5s ease; }
    .bar-legend { display: flex; justify-content: space-between; margin-top: .5rem;
                  color: #cbd5e1; font-size: .85rem; }

    .cta-row { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; align-items: center; }
    .btn-cash { background: #22c55e; color: #052e14; border: 0;
                padding: .8rem 1.25rem; border-radius: .6rem; font-weight: 700;
                font-size: 1rem; cursor: pointer; text-decoration: none;
                display: inline-flex; align-items: center; gap: .5rem; }
    .btn-cash:hover { filter: brightness(1.05); }
    .btn-cash[disabled] { opacity: .5; cursor: not-allowed; }
    .btn-secondary { background: transparent; color: #e2e8f0; border: 1px solid #64748b;
                     padding: .65rem 1rem; border-radius: .6rem; font-weight: 600;
                     font-size: .9rem; text-decoration: none; display: inline-flex; }
    .btn-secondary:hover { border-color: #94a3b8; color: #fff; }
    .save-hint { color: #cbd5e1; font-size: .9rem; align-self: center; }
    .payout-hint { color: #cbd5e1; font-size: .85rem; margin-top: .5rem; }
    .payout-hint a { color: #93c5fd; font-weight: 600; }

    .ladder { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
              gap: 1rem; }
    .tier-card { background: #fff; border-radius: 1rem; padding: 1.25rem;
                 box-shadow: 0 1px 3px rgba(15,23,42,.06);
                 border: 2px solid transparent; position: relative; overflow: hidden; }
    .tier-card.locked { opacity: .7; }
    .tier-card.current { border-color: #0ea5e9; box-shadow: 0 8px 24px rgba(14,165,233,.18); }
    .tier-card.available { border-color: #22c55e; box-shadow: 0 8px 24px rgba(34,197,94,.20); }
    .tier-card.claimed { border-color: #cbd5e1; }
    .tier-card h3 { margin: 0 0 .25rem; font-size: 1.1rem; color: #0f172a; }
    .tier-card .amount { font-size: 2rem; font-weight: 800; color: #0f172a; margin: .5rem 0; }
    .tier-card .th { color: #64748b; font-size: .9rem; }
    .tier-card .badge { display: inline-block; padding: .2rem .6rem; border-radius: 999px;
                        font-size: .7rem; text-transform: uppercase; letter-spacing: .08em;
                        font-weight: 700; }
    .badge.locked { background: #f1f5f9; color: #64748b; }
    .badge.current { background: #e0f2fe; color: #075985; }
    .badge.available { background: #dcfce7; color: #14532d; }
    .badge.bonus { background: #fef3c7; color: #78350f; }

    .journey-empty { background: #fff; border: 2px dashed #cbd5e1; border-radius: 1rem;
                     padding: 2rem; text-align: center; color: #64748b; }

    @media (max-width: 767px) {
        .summary-grid { grid-template-columns: 1fr 1fr; }
        .ladder { grid-template-columns: 1fr; }
        .journey-hero { padding: 1.25rem; border-radius: .75rem; }
        .journey-hero h2 { font-size: 1.35rem; }
        .cta-row { flex-direction: column; align-items: stretch; }
        .btn-cash, .btn-secondary { width: 100%; justify-content: center; }
    }
    @media (max-width: 460px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
    <h1 class="page-h1" data-testid="milestones-page-title">Reward Milestones</h1>
    <p class="lede">Your reward journey — the more approved referrals you bring in, the bigger your rewards.</p>

    @if ($flashError)
        <div class="flash error" role="alert" data-testid="milestone-flash-error">{{ $flashError }}</div>
    @endif
    @if ($flashClaimed)
        <div class="flash success" role="status" data-testid="milestone-flash-success">
            Claim submitted for {{ $flashClaimed['amount'] }}
            @if (($flashClaimed['method'] ?? null) === 'account_credit')
                Account Credit
            @elseif (($flashClaimed['method'] ?? null) === 'bank_transfer')
                Bank Transfer
            @endif
            — awaiting admin approval.
        </div>
    @endif

    <div class="summary-grid" data-testid="milestone-summary">
        <div class="summary-card">
            <h3>Available now</h3>
            <div class="v" data-testid="summary-available">£{{ number_format($summary['available_now_minor'] / 100, 2) }}</div>
        </div>
        <div class="summary-card">
            <h3>Pending approval</h3>
            <div class="v" data-testid="summary-pending">£{{ number_format($summary['pending_minor'] / 100, 2) }}</div>
        </div>
        <div class="summary-card">
            <h3>Awaiting payment</h3>
            <div class="v" data-testid="summary-approved">£{{ number_format($summary['approved_pending_payment_minor'] / 100, 2) }}</div>
        </div>
        <div class="summary-card">
            <h3>Paid</h3>
            <div class="v" data-testid="summary-paid">£{{ number_format($summary['paid_minor'] / 100, 2) }}</div>
        </div>
        <div class="summary-card">
            <h3>Approved referrals</h3>
            <div class="v" data-testid="summary-approved-refs">{{ number_format($summary['approved_referrals']) }}</div>
        </div>
    </div>

    @php
        $maxTier = collect($progress->tiers)->last();
        $isMaxAvailable = $available && $maxTier && $available->id === $maxTier->id;

        $claimButtonLabel = null;
        if ($available && $canClaim) {
            if ($isAccountCredit) {
                $claimButtonLabel = 'Claim £'.number_format($available->accountCreditTotalMinor() / 100, 0).' Account Credit';
            } else {
                $claimButtonLabel = ($isMaxAvailable ? 'Claim' : 'Cash out').' £'.number_format($available->total_reward_amount_minor / 100, 0);
            }
        }
    @endphp
    <section class="journey-hero" data-testid="journey-hero">
        <p class="kicker">Your reward journey</p>

        @if ($isMaxAvailable)
            <h2 data-testid="hero-max-headline">Maximum reward unlocked</h2>
            <p>
                <strong>£{{ number_format($available->total_reward_amount_minor / 100, 0) }}</strong>
                is available to claim. You've reached the maximum reward for this cycle.
                Claim your reward to start your next journey.
            </p>

            @include('ambassador.milestones._payout_choice', ['tier' => $available])

            <div class="cta-row">
                @if ($canClaim)
                    <form method="POST" action="{{ route('ambassador.milestones.claim', $available) }}">
                        @csrf
                        <input type="hidden" name="idempotency_key"
                               value="mc:{{ $profile->id }}:{{ $progress->cycleNumber }}:{{ $available->id }}">
                        <button type="submit" class="btn-cash"
                                data-testid="claim-cta-{{ $available->threshold }}">
                            {{ $claimButtonLabel }}
                        </button>
                    </form>
                @else
                    <div class="flash info" data-testid="claim-requires-payout" style="margin:0;flex:1">
                        Choose how you'd like to receive rewards before claiming.
                        <div style="margin-top:.5rem">
                            <a href="{{ route('ambassador.payout-settings') }}" class="btn-cash"
                               data-testid="set-payout-method-cta">Set payout method</a>
                        </div>
                    </div>
                @endif
                <a href="{{ route('ambassador.payout-settings') }}" class="btn-secondary"
                   data-testid="change-payout-method">Change payout method</a>
            </div>
            @if ($canClaim)
                <p class="payout-hint" data-testid="claim-default-hint">
                    Claiming via your default:
                    <strong>{{ $claimMethod->label() }}</strong>.
                    <a href="{{ route('ambassador.payout-settings') }}">Change payout method</a>
                </p>
            @endif
        @elseif ($available && $next)
            <h2 data-testid="hero-available-headline">
                £{{ number_format($available->total_reward_amount_minor / 100, 0) }} is available to claim
            </h2>
            <p>
                You have <strong>{{ $eligible }}</strong> approved referrals in this cycle.
                Cash out now, or keep going —
                <strong>{{ $progress->referralsRemaining }} more</strong> to unlock
                <strong>£{{ number_format($next->total_reward_amount_minor / 100, 0) }}</strong>@if ($next->bonus_amount_minor > 0)
                (+£{{ number_format($next->bonus_amount_minor / 100, 0) }} Save &amp; Grow bonus)@endif.
            </p>
            <div class="bar" aria-label="Progress toward next reward">
                <div style="width: {{ $progress->progressPercent() }}%"></div>
            </div>
            <div class="bar-legend">
                <span data-testid="hero-progress-count">{{ $eligible }} / {{ $next->threshold }} referrals</span>
                <span data-testid="hero-progress-remaining">{{ $progress->referralsRemaining }} to go</span>
            </div>

            @include('ambassador.milestones._payout_choice', ['tier' => $available])

            <div class="cta-row">
                @if ($canClaim)
                    <form method="POST" action="{{ route('ambassador.milestones.claim', $available) }}">
                        @csrf
                        <input type="hidden" name="idempotency_key"
                               value="mc:{{ $profile->id }}:{{ $progress->cycleNumber }}:{{ $available->id }}">
                        <button type="submit" class="btn-cash"
                                data-testid="claim-cta-{{ $available->threshold }}">
                            {{ $claimButtonLabel }}
                        </button>
                    </form>
                @else
                    <div class="flash info" data-testid="claim-requires-payout" style="margin:0;flex:1">
                        Choose how you'd like to receive rewards before claiming.
                        <div style="margin-top:.5rem">
                            <a href="{{ route('ambassador.payout-settings') }}" class="btn-cash"
                               data-testid="set-payout-method-cta">Set payout method</a>
                        </div>
                    </div>
                @endif
                <a href="{{ route('ambassador.payout-settings') }}" class="btn-secondary"
                   data-testid="change-payout-method">Change payout method</a>
                <span class="save-hint" data-testid="save-and-grow-hint">
                    or keep building — reach {{ $next->threshold }} to unlock
                    £{{ number_format($next->total_reward_amount_minor / 100, 0) }}
                </span>
            </div>
            @if ($canClaim)
                <p class="payout-hint" data-testid="claim-default-hint">
                    Claiming via your default:
                    <strong>{{ $claimMethod->label() }}</strong>.
                    <a href="{{ route('ambassador.payout-settings') }}">Change payout method</a>
                </p>
            @endif
        @elseif ($next)
            <h2 data-testid="hero-progress-headline">
                {{ $eligible }} of {{ $next->threshold }} approved referrals
            </h2>
            <p>
                <strong>{{ $progress->referralsRemaining }} more</strong> referrals to unlock
                <strong>£{{ number_format($next->total_reward_amount_minor / 100, 0) }}</strong>@if ($next->bonus_amount_minor > 0)
                (+£{{ number_format($next->bonus_amount_minor / 100, 0) }} Save &amp; Grow bonus)@endif.
            </p>
            <div class="bar" aria-label="Progress toward next reward">
                <div style="width: {{ $progress->progressPercent() }}%"></div>
            </div>
            <div class="bar-legend">
                <span data-testid="hero-progress-count">{{ $eligible }} / {{ $next->threshold }} referrals</span>
                <span data-testid="hero-progress-remaining">{{ $progress->referralsRemaining }} to go</span>
            </div>
        @else
            <h2>Get started</h2>
            <p>Share your referral link on the dashboard to unlock your first reward.</p>
        @endif
    </section>

    <div class="ladder" data-testid="milestone-ladder">
        @forelse ($progress->tiers as $t)
            @php
                $reached = $eligible >= $t->threshold;
                $isAvailable = $available && $available->id === $t->id;
                $isCurrent = ! $reached && $next && $next->id === $t->id;
                $isMax = $maxTier && $maxTier->id === $t->id;
                $classes = 'tier-card';
                if ($isAvailable) $classes .= ' available';
                elseif ($isCurrent) $classes .= ' current';
                elseif (! $reached) $classes .= ' locked';
                else $classes .= ' claimed';
            @endphp
            <div class="{{ $classes }}" data-testid="tier-card-{{ $t->threshold }}">
                @if ($isAvailable)
                    <span class="badge available">£{{ number_format($t->total_reward_amount_minor / 100, 0) }} unlocked</span>
                @elseif ($isCurrent)
                    <span class="badge current">In progress</span>
                @elseif ($reached)
                    <span class="badge current">Reached</span>
                @else
                    <span class="badge locked">Locked</span>
                @endif
                @if ($isMax)
                    <span class="badge bonus" data-testid="tier-{{ $t->threshold }}-max-badge"
                          style="margin-left:.35rem">MAX REWARD</span>
                @endif
                <h3>{{ $t->title }}</h3>
                <div class="amount">£{{ number_format($t->total_reward_amount_minor / 100, 0) }}</div>
                <div class="th">Unlocks at {{ $t->threshold }} approved referrals</div>
                @if ($t->bonus_amount_minor > 0)
                    <div style="margin-top:.5rem"><span class="badge bonus">+£{{ number_format($t->bonus_amount_minor / 100, 0) }} Save &amp; Grow bonus</span></div>
                @endif
                @if ($isCurrent)
                    <div style="margin-top:.75rem;color:#475569;font-size:.9rem">
                        {{ max(0, $t->threshold - $eligible) }} more to unlock
                    </div>
                @endif
                @if ($t->description)
                    <div style="margin-top:.5rem;color:#64748b;font-size:.85rem">{{ $t->description }}</div>
                @endif
            </div>
        @empty
            <div class="journey-empty">No reward tiers are configured yet.</div>
        @endforelse

        @if ($isMaxAvailable)
            <div class="tier-card locked" data-testid="tier-card-max-cycle-note">
                <span class="badge current">Max cycle</span>
                <h3>Maximum reward reached</h3>
                <div style="color:#64748b;font-size:.9rem;margin-top:.5rem">
                    Claim your reward to start your next journey. The ladder resets and you can climb it again.
                </div>
            </div>
        @endif
    </div>
@endsection
