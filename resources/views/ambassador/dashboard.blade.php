@extends('layouts.ambassador')

@section('title', 'Dashboard')

@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    /** @var ?\App\Models\AmbassadorProfile $profile */
    $profile = $user->ambassadorProfile;
    $referralUrl = $profile?->referralUrl();

    // Only THIS ambassador's data. Bots are excluded from user-visible counts.
    $validClicks = 0;
    $clicks30d = 0;
    $recentClicks = collect();
    if ($profile) {
        $base = \App\Models\ReferralClick::query()
            ->where('ambassador_profile_id', $profile->id)
            ->where('is_bot', false);
        $validClicks = (clone $base)->count();
        $clicks30d = (clone $base)->where('created_at', '>=', now()->subDays(30))->count();
        $recentClicks = (clone $base)->orderByDesc('created_at')->limit(10)->get();
    }

    $whatsappShare = $referralUrl
        ? 'https://api.whatsapp.com/send?text=' . urlencode(
            "Join AIO Media via my referral: {$referralUrl}"
        )
        : null;
@endphp

@push('head')
<style>
    .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-top: 1.5rem; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem; }
    .card { background: #fff; border-radius: 1rem; padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 3px rgba(15,23,42,.06); }
    .card h2 { margin: 0 0 .5rem; font-size: .75rem; text-transform: uppercase;
               letter-spacing: .08em; color: #64748b; }
    .value { font-size: 1.5rem; font-weight: 700; color: #0f172a; word-break: break-word; }
    .value-sub { font-size: .85rem; color: #64748b; margin-top: .25rem; }
    .referral-row { display: flex; gap: .5rem; margin-top: .5rem; flex-wrap: wrap; }
    .referral-row input { flex: 1; min-width: 240px; padding: .6rem .75rem;
                          border: 1px solid #cbd5e1; border-radius: .5rem;
                          font-family: ui-monospace, monospace; font-size: .9rem;
                          background: #f8fafc; }
    .btn-primary { padding: .6rem 1rem; background: #0f172a; color: #fff;
                   border: 0; border-radius: .5rem; font-weight: 600; cursor: pointer;
                   text-decoration: none; display: inline-block; }
    .btn-whatsapp { padding: .6rem 1rem; background: #25d366; color: #fff;
                    border: 0; border-radius: .5rem; font-weight: 600;
                    text-decoration: none; display: inline-block; }
    .status-pill { display: inline-block; padding: .2rem .7rem; border-radius: 999px;
                   background: #dcfce7; color: #14532d; font-size: .85rem; font-weight: 500; }
    .status-pill.warn { background: #fef3c7; color: #78350f; }
    table.clicks { width: 100%; border-collapse: collapse; margin-top: .75rem; font-size: .9rem; }
    table.clicks th, table.clicks td { text-align: left; padding: .5rem .5rem;
                                        border-bottom: 1px solid #f1f5f9; }
    table.clicks th { color: #64748b; font-weight: 500; text-transform: uppercase;
                      letter-spacing: .05em; font-size: .75rem; }
    .empty { color: #94a3b8; padding: 1rem 0; }
    .progress { height: 8px; background: #f1f5f9; border-radius: 999px; overflow: hidden; margin-top:.5rem; }
    .progress > div { height: 100%; background: #0f172a; width: 0%; }

    /* Mobile stacked-card view for the recent clicks table. */
    .clicks-mobile { display: none; margin-top: .75rem; }
    .clicks-mobile .click-row {
        display: grid; grid-template-columns: 1fr auto; gap: .35rem .75rem;
        padding: .75rem 0; border-bottom: 1px solid #f1f5f9;
    }
    .clicks-mobile .click-row dt { color: #64748b; font-size: .75rem;
        text-transform: uppercase; letter-spacing: .05em; margin: 0; }
    .clicks-mobile .click-row dd { margin: 0; font-size: .9rem;
        overflow: hidden; text-overflow: ellipsis; }
    .clicks-mobile .click-row .when { grid-column: 1 / -1; color: #0f172a; font-weight: 600; }

    /* ── Tablet ────────────────────────────────────────────────── */
    @media (max-width: 1023px) {
        .grid { grid-template-columns: repeat(2, 1fr); }
    }

    /* ── Mobile ────────────────────────────────────────────────── */
    @media (max-width: 767px) {
        .grid { grid-template-columns: 1fr 1fr; gap: .75rem; }
        .grid2 { grid-template-columns: 1fr; gap: .75rem; }
        .card { padding: 1rem; border-radius: .75rem; }
        .card h2 { font-size: .7rem; }
        .value { font-size: 1.2rem; }
        h1 { font-size: 1.5rem !important; }

        /* Referral card: stack input + buttons full width. */
        .referral-row { flex-direction: column; align-items: stretch; }
        .referral-row input { min-width: 0; width: 100%; }
        .referral-row .btn-primary,
        .referral-row .btn-whatsapp { width: 100%; text-align: center; }

        /* Table → hide, show stacked-card list. */
        table.clicks { display: none; }
        .clicks-mobile { display: block; }
    }

    /* Very narrow (small phones): 1 col for stats too. */
    @media (max-width: 420px) {
        .grid { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
    <h1 style="margin:0 0 .25rem">Welcome, {{ $user->name }}</h1>
    <p style="color:#475569;margin:0" data-testid="rewards-dashboard-subtitle">
        Rewards dashboard — share your link. Every completed purchase counts toward your next reward.
    </p>

    @if ($profile)
        {{-- Referral link + share --}}
        <div class="card" style="margin-top:1.5rem">
            <h2>Your unique referral link</h2>
            <div class="referral-row">
                <input type="text" readonly value="{{ $referralUrl }}"
                       id="referral-link-input" data-testid="referral-link-input"
                       onclick="this.select()">
                <button type="button" class="btn-primary" data-testid="copy-referral-link"
                        onclick="
                            const el = document.getElementById('referral-link-input');
                            el.select();
                            navigator.clipboard.writeText(el.value).then(() => {
                                const s = document.getElementById('copy-status');
                                s.textContent = 'Copied!';
                                setTimeout(() => s.textContent = '', 1500);
                            });
                        ">Copy link</button>
                @if ($whatsappShare)
                    <a href="{{ $whatsappShare }}" target="_blank" rel="noopener"
                       class="btn-whatsapp" data-testid="share-whatsapp">
                        Share via WhatsApp
                    </a>
                @endif
            </div>
            <div id="copy-status" data-testid="copy-status"
                 style="margin-top:.4rem;color:#065f46;font-size:.85rem;height:1rem"></div>
            <div style="margin-top:.5rem;color:#64748b;font-size:.85rem">
                Code: <strong data-testid="dash-referral-code">{{ $profile->referral_code }}</strong>
            </div>
        </div>

        {{-- Stat cards --}}
        <div class="grid">
            <div class="card">
                <h2>Total valid clicks</h2>
                <div class="value" data-testid="stat-total-clicks">{{ number_format($validClicks) }}</div>
                <div class="value-sub">Since activation</div>
            </div>
            <div class="card">
                <h2>Clicks last 30 days</h2>
                <div class="value" data-testid="stat-clicks-30d">{{ number_format($clicks30d) }}</div>
                <div class="value-sub">Rolling window</div>
            </div>
            <div class="card">
                <h2>Pending referrals</h2>
                <div class="value" data-testid="stat-pending">0</div>
                <div class="value-sub">Awaiting purchase (Phase 3+)</div>
            </div>
            <div class="card">
                <h2>Approved referrals</h2>
                <div class="value" data-testid="stat-approved">{{ number_format($stats['approved_conversions']) }}</div>
                <div class="value-sub">Cleared refund window</div>
            </div>
        </div>

        {{-- Rewards summary --}}
        <div class="grid" style="margin-top:1rem" data-testid="rewards-summary">
            <div class="card">
                <h2>Pending reward</h2>
                <div class="value" data-testid="reward-pending">£{{ number_format($stats['pending_reward_minor'] / 100, 2) }}</div>
                <div class="value-sub">Awaiting admin approval</div>
            </div>
            <div class="card">
                <h2>Approved reward</h2>
                <div class="value" data-testid="reward-approved">£{{ number_format($stats['approved_reward_minor'] / 100, 2) }}</div>
                <div class="value-sub">Ready for payout</div>
            </div>
            <div class="card">
                <h2>Paid reward</h2>
                <div class="value" data-testid="reward-paid">£{{ number_format($stats['paid_reward_minor'] / 100, 2) }}</div>
                <div class="value-sub">Sent to you</div>
            </div>
            <div class="card">
                <h2>Lifetime earned</h2>
                <div class="value" data-testid="reward-lifetime">£{{ number_format($stats['lifetime_earned_minor'] / 100, 2) }}</div>
                <div class="value-sub">Approved + paid</div>
            </div>
        </div>

        <div class="grid2">
            {{-- Reward progress --}}
            <div class="card">
                <h2>Next milestone</h2>
                @if ($stats['next_rule'])
                    @php
                        $pct = $stats['progress_target'] > 0
                            ? min(100, round(($stats['progress_current'] / $stats['progress_target']) * 100))
                            : 0;
                    @endphp
                    <div class="value" data-testid="milestone-progress">{{ $stats['progress_current'] }} / {{ $stats['progress_target'] }} referrals</div>
                    <div class="progress"><div style="width:{{ $pct }}%"></div></div>
                    <div class="value-sub" data-testid="milestone-remaining">
                        {{ $stats['progress_remaining'] }} {{ \Illuminate\Support\Str::plural('referral', $stats['progress_remaining']) }}
                        until your next {{ $stats['next_rule']->amountFormatted() }} reward.
                    </div>
                @else
                    <div class="value" data-testid="milestone-progress">—</div>
                    <div class="value-sub">No active reward rules yet.</div>
                @endif
            </div>

            {{-- Status --}}
            <div class="card">
                <h2>Account status</h2>
                @if ($profile->flagged_for_review)
                    <span class="status-pill warn" data-testid="dash-status">Under review</span>
                @else
                    <span class="status-pill" data-testid="dash-status">Active</span>
                @endif
                <div class="value-sub" style="margin-top:.6rem">
                    Activated {{ $profile->activated_at?->diffForHumans() }}
                </div>
            </div>
        </div>

        {{-- Recent clicks --}}
        <div class="card" style="margin-top:1.5rem">
            <h2>Recent valid clicks</h2>
            @if ($recentClicks->isEmpty())
                <div class="empty" data-testid="recent-clicks-empty">
                    No clicks yet — share your link to get started.
                </div>
            @else
                <table class="clicks" data-testid="recent-clicks-table">
                    <thead>
                        <tr><th>When</th><th>UTM source</th><th>UTM medium</th><th>Referrer</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recentClicks as $c)
                            <tr>
                                <td>{{ $c->created_at->diffForHumans() }}</td>
                                <td>{{ $c->utm_source ?: '—' }}</td>
                                <td>{{ $c->utm_medium ?: '—' }}</td>
                                <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    {{ $c->referer_url ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Mobile stacked-card view (CSS hides on ≥768px). UTM
                     source/medium are dropped on mobile per spec — the
                     underlying data is unchanged; only the presentation
                     differs. --}}
                <div class="clicks-mobile" data-testid="recent-clicks-mobile">
                    @foreach ($recentClicks as $c)
                        <dl class="click-row">
                            <dd class="when">{{ $c->created_at->diffForHumans() }}</dd>
                            <dt>Referrer</dt>
                            <dd title="{{ $c->referer_url ?: '' }}">{{ $c->referer_url ?: '—' }}</dd>
                        </dl>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <div class="card" style="margin-top:1.5rem;background:#fef2f2;color:#991b1b">
            You don't have a Rewards Member profile yet. Please contact support.
        </div>
    @endif
@endsection
