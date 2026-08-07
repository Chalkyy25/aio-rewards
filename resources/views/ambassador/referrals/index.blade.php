@extends('layouts.ambassador')

@section('title', 'My Referrals')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $conversions */
@endphp

@push('head')
<style>
    h1.page-h1 { font-size: 1.8rem; margin: 0 0 .25rem; }
    .lede { color: #475569; margin: 0 0 1.5rem; }
    .ref-list { display: grid; grid-template-columns: 1fr; gap: .6rem; }
    .ref-card { background: #fff; border-radius: .75rem; padding: 1rem 1.25rem;
                box-shadow: 0 1px 3px rgba(15,23,42,.06);
                display: grid; grid-template-columns: 1fr auto; gap: .35rem 1rem;
                align-items: start; }
    .ref-card h3 { margin: 0; font-size: 1rem; color: #0f172a; }
    .ref-card .meta { color: #64748b; font-size: .85rem; margin-top: .2rem; }
    .pill { display: inline-block; padding: .15rem .6rem; border-radius: 999px;
            font-size: .75rem; font-weight: 600; }
    .pill.approved { background: #dcfce7; color: #14532d; }
    .pill.pending { background: #fef3c7; color: #78350f; }
    .pill.reversed { background: #fee2e2; color: #991b1b; }
    .empty { background: #fff; border: 2px dashed #cbd5e1; border-radius: .75rem;
             padding: 2rem; text-align: center; color: #64748b; }

    @media (max-width: 640px) {
        .ref-card { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
    <h1 class="page-h1" data-testid="referrals-page-title">My Referrals</h1>
    <p class="lede">Which referrals are counting toward your rewards.</p>

    @if ($conversions->isEmpty())
        <div class="empty" data-testid="referrals-empty">
            No referrals yet. Share your link to get started.
        </div>
    @else
        <div class="ref-list" data-testid="referrals-list">
            @foreach ($conversions as $c)
                @php
                    $status = match ($c->status) {
                        'approved' => ['approved', 'Approved', 'Counts toward rewards'],
                        'pending' => ['pending', 'Pending', 'Counts when approved'],
                        'reversed' => ['reversed', 'Reversed', 'Does not count'],
                        default => ['pending', ucfirst($c->status), ''],
                    };
                    $pkg = $c->purchase?->package?->name ?? 'AIO Media package';
                @endphp
                <article class="ref-card" data-testid="referral-card-{{ $c->id }}">
                    <div>
                        <h3>Referral #{{ $c->id }}</h3>
                        <div class="meta">
                            Package: {{ $pkg }}
                        </div>
                        <div class="meta">
                            {{ $status[2] }}
                            @if ($c->approved_at) · Approved {{ $c->approved_at->format('j M Y') }}@endif
                        </div>
                    </div>
                    <div>
                        <span class="pill {{ $status[0] }}"
                              data-testid="referral-status-{{ $c->id }}">{{ $status[1] }}</span>
                    </div>
                </article>
            @endforeach
        </div>
        <div style="margin-top:1rem">{{ $conversions->links() }}</div>
    @endif
@endsection
