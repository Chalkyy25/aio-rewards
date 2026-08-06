@extends('layouts.public')
@section('title', 'AIO Rewards — AIO Media, VPN & streaming')
@section('content')
    @if (request()->cookie(config('referrals.cookie.name', 'aior_ref')))
        @php $refName = \App\Support\ReferralContext::referringName(); @endphp
        <div data-testid="referral-badge" style="background:#dcfce7;color:#065f46;padding:.6rem 1rem;border-radius:.5rem;margin-bottom:1.5rem">
            @if ($refName)
                You were referred by <strong data-testid="referral-name">{{ $refName }}</strong>. Complete your purchase to support them.
            @else
                You were referred by an AIO Rewards ambassador. Complete your purchase to support them.
            @endif
        </div>
    @endif

    <section style="max-width:820px" data-testid="section-buyer">
        <h1 style="font-size:2.2rem;letter-spacing:-0.02em;margin:0 0 .5rem" data-testid="landing-heading">{{ settings('public.landing_heading') }}</h1>
        <p style="color:#334155;font-size:1.05rem;max-width:640px;line-height:1.55" data-testid="landing-subheading">
            {{ settings('public.landing_subheading') }}
        </p>
        <a href="{{ route('packages') }}" data-testid="cta-packages"
           style="display:inline-block;margin-top:1rem;padding:.9rem 1.6rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600">
            View packages
        </a>
    </section>

    <hr style="margin:3rem 0;border:0;border-top:1px solid #e2e8f0">

    @auth
        @php
            $user = auth()->user();
            $hasPanel = $user->hasAnyRole(\App\Enums\Role::panelRoles());
            $hasAmbassador = $user->hasRole(\App\Enums\Role::Ambassador->value);
        @endphp
        <section style="max-width:820px" data-testid="section-authenticated">
            <h2 style="font-size:1.25rem;margin:0 0 .4rem">Welcome back, {{ $user->name }}.</h2>
            <p style="color:#475569;margin:0 0 1rem">You are signed in — jump straight to your workspace.</p>

            @if ($hasPanel && $hasAmbassador)
                <a href="{{ route('post-login.choose') }}" data-testid="cta-post-login"
                   style="display:inline-block;padding:.6rem 1.2rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600;margin-right:.5rem">
                    Choose where to go
                </a>
            @elseif ($hasPanel)
                <a href="/admin" data-testid="cta-admin-panel"
                   style="display:inline-block;padding:.6rem 1.2rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600;margin-right:.5rem">
                    Open admin panel
                </a>
            @else
                <a href="{{ route('ambassador.dashboard') }}" data-testid="cta-open-dashboard"
                   style="display:inline-block;padding:.6rem 1.2rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600;margin-right:.5rem">
                    Open my dashboard
                </a>
            @endif

            <form method="POST" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button type="submit" data-testid="cta-signout"
                        style="padding:.6rem 1.2rem;background:transparent;border:0;color:#0f172a;text-decoration:underline;font-weight:500;cursor:pointer">
                    Sign out
                </button>
            </form>
        </section>
    @else
        <section style="max-width:820px" data-testid="section-existing">
            <h2 style="font-size:1.25rem;margin:0 0 .4rem">Already an AIO Media customer?</h2>
            <p style="color:#475569;margin:0 0 1rem">Activate your AIO Rewards ambassador account, earn rewards on referrals, or sign in.</p>
            <a href="{{ route('activate') }}" data-testid="cta-activate"
               style="display:inline-block;padding:.6rem 1.2rem;background:#fff;border:1px solid #cbd5e1;color:#0f172a;border-radius:.5rem;text-decoration:none;font-weight:600;margin-right:.5rem">
                Activate Ambassador account
            </a>
            <a href="{{ route('login') }}" data-testid="cta-login"
               style="display:inline-block;padding:.6rem 1.2rem;color:#0f172a;text-decoration:underline;font-weight:500">
                Sign in
            </a>
        </section>
    @endauth
@endsection
