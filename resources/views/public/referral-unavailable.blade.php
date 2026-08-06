@extends('layouts.public')

@section('title', 'Referral link unavailable')

@section('content')
    <section style="max-width:560px;margin:3rem auto;background:#fff;padding:2.5rem;border-radius:1rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12);text-align:center">
        <h1 style="margin:0 0 .75rem;font-size:1.5rem">Referral link unavailable</h1>
        <p style="color:#475569">
            @if (($reason ?? 'notfound') === 'busy')
                We're seeing a lot of traffic on this link right now. Please try again in a moment.
            @else
                This referral link is no longer active. It may have been mistyped or the referring Rewards Member is no longer in the programme.
            @endif
        </p>
        <p style="margin-top:1.5rem">
            <a href="{{ route('home') }}" data-testid="ref-unavailable-home"
               style="display:inline-block;padding:.7rem 1.25rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600">
                Continue to AIO Rewards
            </a>
        </p>
    </section>
@endsection
