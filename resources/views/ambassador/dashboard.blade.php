@extends('layouts.ambassador')

@section('title', 'Dashboard')

@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    /** @var ?\App\Models\AmbassadorProfile $profile */
    $profile = $user->ambassadorProfile;
    $referralUrl = $profile?->referralUrl();
@endphp

@push('head')
<style>
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem; }
    .card { background: #fff; border-radius: 1rem; padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(15,23,42,.06); }
    .card h2 { margin: 0 0 .75rem; font-size: .8rem; text-transform: uppercase;
               letter-spacing: .08em; color: #64748b; }
    .value { font-size: 1.25rem; font-weight: 600; color: #0f172a; word-break: break-all; }
    .referral-row { display: flex; gap: .5rem; margin-top: .5rem; }
    .referral-row input { flex: 1; padding: .6rem .75rem; border: 1px solid #cbd5e1;
                          border-radius: .5rem; font-family: ui-monospace, monospace;
                          font-size: .9rem; background: #f8fafc; }
    .referral-row button { padding: .6rem 1rem; background: #0f172a; color: #fff; border: 0;
                           border-radius: .5rem; font-weight: 600; cursor: pointer; }
    .status-pill { display: inline-block; padding: .2rem .7rem; border-radius: 999px;
                   background: #dcfce7; color: #14532d; font-size: .85rem; font-weight: 500; }
    .status-pill.warn { background: #fef3c7; color: #78350f; }
    @media (max-width: 720px) { .grid { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
    <h1 style="margin:0 0 .25rem">Welcome, {{ $user->name }}</h1>
    <p style="color:#475569;margin:0">Share your link below. Every completed purchase counts toward your next reward.</p>

    @if (request()->query('verified'))
        <div data-testid="verified-flash" style="margin-top:1rem;background:#ecfdf5;color:#065f46;padding:.75rem 1rem;border-radius:.5rem">
            Your email has been verified.
        </div>
    @endif

    @if ($profile)
        <div class="card" style="margin-top:1.5rem">
            <h2>Your unique referral link</h2>
            <div class="referral-row">
                <input type="text" readonly value="{{ $referralUrl }}"
                       data-testid="referral-link-input"
                       id="referral-link-input" onclick="this.select()">
                <button type="button" data-testid="copy-referral-link"
                        onclick="
                            const el = document.getElementById('referral-link-input');
                            el.select();
                            navigator.clipboard.writeText(el.value).then(() => {
                                const s = document.getElementById('copy-status');
                                s.textContent = 'Copied!';
                                setTimeout(() => s.textContent = '', 1500);
                            });
                        ">Copy link</button>
            </div>
            <div id="copy-status" data-testid="copy-status"
                 style="margin-top:.4rem;color:#065f46;font-size:.85rem;height:1rem"></div>
        </div>

        <div class="grid">
            <div class="card">
                <h2>Name</h2>
                <div class="value" data-testid="dash-name">{{ $user->name }}</div>
            </div>
            <div class="card">
                <h2>Email</h2>
                <div class="value" data-testid="dash-email">{{ $user->email }}</div>
            </div>
            <div class="card">
                <h2>Referral code</h2>
                <div class="value" data-testid="dash-referral-code">{{ $profile->referral_code }}</div>
            </div>
            <div class="card">
                <h2>Status</h2>
                @if ($profile->flagged_for_review)
                    <span class="status-pill warn" data-testid="dash-status">Under review</span>
                @else
                    <span class="status-pill" data-testid="dash-status">Active</span>
                @endif
            </div>
        </div>

        <p style="color:#94a3b8;margin-top:2rem;font-size:.85rem">
            Click tracking, conversion counts and reward milestones will appear here as they come online in later phases.
        </p>
    @else
        <div class="card" style="margin-top:1.5rem;background:#fef2f2;color:#991b1b">
            You don't have an ambassador profile yet. If you believe this is an error, please contact support.
        </div>
    @endif
@endsection
