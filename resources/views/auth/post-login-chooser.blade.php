@extends('layouts.public')

@section('title', 'Where to next?')

@push('head')
<style>
    .chooser-wrap{max-width:640px;margin:3rem auto;padding:0 1rem}
    .chooser-card{background:#fff;border-radius:1rem;padding:2rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12);text-align:center}
    .chooser-card h1{margin:0 0 .5rem;font-size:1.5rem}
    .chooser-card p{color:#64748b;margin:0 0 1.5rem}
    .chooser-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:left}
    @media (max-width:640px){.chooser-grid{grid-template-columns:1fr}}
    .chooser-tile{display:block;padding:1.25rem 1.5rem;border-radius:.75rem;text-decoration:none;border:1px solid #e2e8f0;transition:all .15s ease}
    .chooser-tile:hover{border-color:#0f172a;transform:translateY(-1px)}
    .chooser-tile .title{font-weight:600;color:#0f172a;font-size:1rem}
    .chooser-tile .subtitle{color:#64748b;font-size:.85rem;margin-top:.25rem}
    .chooser-primary{background:#0f172a;color:#fff;border-color:#0f172a}
    .chooser-primary .title,.chooser-primary .subtitle{color:#fff}
    .chooser-primary .subtitle{color:#cbd5e1}
    .signout{margin-top:1.5rem}
    .signout button{background:none;border:0;color:#64748b;cursor:pointer;font-size:.85rem;text-decoration:underline}
</style>
@endpush

@section('content')
    <div class="chooser-wrap" data-testid="post-login-chooser">
        <div class="chooser-card">
            <h1>Welcome back, {{ auth()->user()->name }}</h1>
            <p>You have access to both areas. Where would you like to go?</p>
            <div class="chooser-grid">
                <a href="{{ route('ambassador.dashboard') }}" class="chooser-tile" data-testid="chooser-ambassador">
                    <div class="title">Open Ambassador Dashboard</div>
                    <div class="subtitle">Your referral link, clicks, conversions and rewards.</div>
                </a>
                <a href="/admin" class="chooser-tile chooser-primary" data-testid="chooser-admin">
                    <div class="title">Open Admin Panel</div>
                    <div class="subtitle">Manage packages, orders, rules and ambassadors.</div>
                </a>
            </div>
            <div class="signout">
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button type="submit" data-testid="chooser-signout">Not you? Sign out</button>
                </form>
            </div>
        </div>
    </div>
@endsection
