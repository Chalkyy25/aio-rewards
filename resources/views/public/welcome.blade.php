@extends('layouts.public')

@section('title', 'AIO Rewards — Earn from every referral')

@section('content')
    <section style="max-width:720px">
        <h1 style="font-size:2.5rem;letter-spacing:-0.02em;margin:0 0 1rem">Turn your AIO Media subscription into rewards.</h1>
        <p style="max-width:640px;color:#334155;font-size:1.1rem;line-height:1.6">
            Already an AIO Media customer? Activate your Ambassador account, share your unique link,
            and earn rewards when friends and family sign up.
        </p>
        <div style="margin-top:2rem;display:flex;gap:1rem">
            <a href="{{ route('activate') }}"
               data-testid="cta-activate"
               style="display:inline-block;padding:.85rem 1.5rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600">
                Activate my Ambassador account
            </a>
            <a href="{{ route('login') }}"
               data-testid="cta-login"
               style="display:inline-block;padding:.85rem 1.5rem;background:#fff;border:1px solid #cbd5e1;color:#0f172a;border-radius:.5rem;text-decoration:none;font-weight:600">
                Sign in
            </a>
        </div>
    </section>
@endsection
