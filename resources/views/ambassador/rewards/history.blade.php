@extends('layouts.ambassador')

@section('title', 'Reward History')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $rewards */
@endphp

@push('head')
<style>
    h1.page-h1 { font-size: 1.8rem; margin: 0 0 .25rem; }
    .lede { color: #475569; margin: 0 0 1.5rem; }

    .history-list { display: grid; grid-template-columns: 1fr; gap: .75rem; }
    .history-card { background: #fff; border-radius: .75rem;
                    padding: 1.1rem 1.25rem; box-shadow: 0 1px 3px rgba(15,23,42,.06);
                    display: grid; grid-template-columns: 1fr auto; gap: .5rem 1rem;
                    align-items: start; }
    .history-card .amt { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
    .history-card h3 { margin: 0; font-size: 1rem; color: #0f172a; }
    .history-card .meta { color: #64748b; font-size: .85rem; margin-top: .25rem; }
    .history-card .status { text-align: right; }
    .pill { display: inline-block; padding: .15rem .6rem; border-radius: 999px;
            font-size: .75rem; font-weight: 600; }
    .pill.pending { background: #fef3c7; color: #78350f; }
    .pill.approved { background: #dbeafe; color: #1e3a8a; }
    .pill.paid { background: #dcfce7; color: #14532d; }
    .pill.rejected { background: #f1f5f9; color: #475569; }
    .pill.reversed { background: #fee2e2; color: #991b1b; }
    .empty { background: #fff; border: 2px dashed #cbd5e1; border-radius: .75rem;
             padding: 2rem; text-align: center; color: #64748b; }
    .pagination-wrap { margin-top: 1rem; }

    @media (max-width: 640px) {
        .history-card { grid-template-columns: 1fr; }
        .history-card .status { text-align: left; }
    }
</style>
@endpush

@section('content')
    <h1 class="page-h1" data-testid="history-page-title">Reward History</h1>
    <p class="lede">Every claim you've made — from awaiting approval through to paid.</p>

    @if ($rewards->isEmpty())
        <div class="empty" data-testid="history-empty">
            No rewards yet. Keep sharing your link — your first milestone is on the way.
        </div>
    @else
        <div class="history-list" data-testid="history-list">
            @foreach ($rewards as $r)
                @php
                    $statusPill = match ($r->status) {
                        'pending_approval' => ['pending', 'Awaiting approval'],
                        'approved' => ['approved', 'Payment pending'],
                        'paid' => ['paid', 'Paid'],
                        'rejected' => ['rejected', 'Rejected'],
                        'reversed' => ['reversed', 'Reversed'],
                        default => ['rejected', ucfirst($r->status)],
                    };
                    $threshold = $r->tier_snapshot['threshold'] ?? $r->tier?->threshold ?? $r->milestone_index;
                    $title = $r->tier_snapshot['title'] ?? $r->tier?->title ?? 'Milestone reward';
                @endphp
                <article class="history-card" data-testid="history-card-{{ $r->id }}">
                    <div>
                        <h3>{{ $title }}</h3>
                        <div class="meta">
                            {{ $threshold }} referrals · Claimed {{ $r->created_at->format('j M Y') }}
                            @if ($r->approved_at) · Approved {{ $r->approved_at->format('j M Y') }}@endif
                            @if ($r->paid_at) · Paid {{ $r->paid_at->format('j M Y') }}@endif
                        </div>
                    </div>
                    <div class="status">
                        <div class="amt" data-testid="history-amount-{{ $r->id }}">{{ $r->amountFormatted() }}</div>
                        <span class="pill {{ $statusPill[0] }}"
                              data-testid="history-status-{{ $r->id }}">{{ $statusPill[1] }}</span>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="pagination-wrap">
            {{ $rewards->links() }}
        </div>
    @endif
@endsection
