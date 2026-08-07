<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'My Rewards — ' . config('app.name'))</title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <link rel="icon" type="image/png" href="{{ asset('images/aio-favicon.png') }}?v=2">
    <link rel="apple-touch-icon" href="{{ asset('images/aio-favicon.png') }}?v=2">
    <link rel="shortcut icon" href="{{ asset('images/aio-favicon.png') }}?v=2">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; --brand-dark:#0f172a; --brand-fg:#e2e8f0; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif;
                     background: #f5f5f4; color: #111; }
        body.drawer-open { overflow: hidden; }

        /* ── Shell (desktop-first) ───────────────────────────────────── */
        .shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        aside.sidebar { background: var(--brand-dark); color: var(--brand-fg); padding: 1.5rem; }
        aside.sidebar a, aside.sidebar button {
            color: #cbd5e1; display: block; padding: .55rem 0;
            text-decoration: none; background: none; border: 0;
            text-align: left; font: inherit; cursor: pointer; width:100%;
            font-size: .95rem;
        }
        aside.sidebar a:hover, aside.sidebar button:hover { color: #fff; }
        aside.sidebar .brand { margin-bottom: 1.25rem; }
        aside.sidebar .divider { border-top: 1px solid #1e293b; margin: 1rem 0; }

        main { padding: 2rem; max-width: 1080px; width: 100%; }

        /* ── Mobile top bar (visible < 768px) ────────────────────────── */
        .mobile-topbar { display: none; }
        .mobile-topbar {
            background: var(--brand-dark); color: #fff; padding: .75rem 1rem;
            align-items: center; justify-content: space-between; gap: .75rem;
            position: sticky; top: 0; z-index: 30;
            box-shadow: 0 2px 6px rgba(15,23,42,.08);
        }
        .mobile-topbar .hamburger {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: .5rem;
            background: rgba(255,255,255,.08); color: #fff;
            border: 0; cursor: pointer; padding: 0;
        }
        .mobile-topbar .hamburger:hover { background: rgba(255,255,255,.16); }
        .mobile-topbar .hamburger svg { width: 22px; height: 22px; }
        .mobile-topbar-brand { display: inline-flex; align-items: center; }

        /* ── Mobile drawer (hidden by default) ───────────────────────── */
        .drawer-backdrop {
            position: fixed; inset: 0; background: rgba(2,6,23,.6);
            opacity: 0; pointer-events: none; transition: opacity .18s ease;
            z-index: 40;
        }
        .drawer {
            position: fixed; inset: 0 auto 0 0; width: 82%; max-width: 320px;
            background: var(--brand-dark); color: var(--brand-fg);
            padding: 1.25rem 1.25rem 2rem; z-index: 50;
            transform: translateX(-100%); transition: transform .22s ease;
            display: flex; flex-direction: column;
            box-shadow: 6px 0 24px rgba(0,0,0,.35);
        }
        .drawer .drawer-close {
            align-self: flex-end; background: transparent; border: 0;
            color: #cbd5e1; cursor: pointer; padding: .25rem; margin-bottom: .5rem;
        }
        .drawer .brand { margin-bottom: 1.25rem; }
        .drawer a, .drawer button {
            color: #cbd5e1; display: block; padding: .7rem 0;
            text-decoration: none; background: none; border: 0;
            text-align: left; font: inherit; cursor: pointer; width:100%;
            font-size: 1rem;
        }
        .drawer a:hover, .drawer button:hover { color: #fff; }
        .drawer .divider { border-top: 1px solid #1e293b; margin: .75rem 0; }

        body.drawer-open .drawer-backdrop { opacity: 1; pointer-events: auto; }
        body.drawer-open .drawer { transform: translateX(0); }

        /* ── Tablet: keep sidebar but narrower ───────────────────────── */
        @media (max-width: 1023px) and (min-width: 768px) {
            .shell { grid-template-columns: 200px 1fr; }
            aside.sidebar { padding: 1rem; }
            main { padding: 1.5rem; }
        }

        /* ── Mobile: hide sidebar entirely, show top bar & drawer ────── */
        @media (max-width: 767px) {
            .shell { grid-template-columns: 1fr; }
            aside.sidebar { display: none; }
            .mobile-topbar { display: flex; }
            main { padding: 1rem; max-width: 100%; }
            .drawer { display: flex; }
        }
        @media (min-width: 768px) {
            .drawer, .drawer-backdrop { display: none !important; }
        }
    </style>
    @stack('head')
    @livewireStyles
</head>
<body data-testid="member-layout-body">
    {{-- Mobile top bar (only rendered ≤767px via CSS) --}}
    <header class="mobile-topbar" data-testid="mobile-topbar" role="banner">
        <div class="mobile-topbar-brand">
            @include('partials.logo', ['variant' => 'dark', 'height' => 32, 'testid' => 'brand-logo-mobile'])
        </div>
        <button type="button" class="hamburger"
                data-testid="mobile-hamburger"
                aria-label="Open navigation menu"
                aria-controls="member-drawer"
                aria-expanded="false"
                onclick="window.aioDrawer && window.aioDrawer.open()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="4" y1="7" x2="20" y2="7"></line>
                <line x1="4" y1="12" x2="20" y2="12"></line>
                <line x1="4" y1="17" x2="20" y2="17"></line>
            </svg>
        </button>
    </header>

    {{-- Mobile drawer + backdrop --}}
    <div class="drawer-backdrop" data-testid="mobile-drawer-backdrop"
         onclick="window.aioDrawer && window.aioDrawer.close()"></div>
    <nav id="member-drawer" class="drawer" role="dialog" aria-modal="true"
         aria-label="Member navigation" data-testid="mobile-drawer" tabindex="-1">
        <button type="button" class="drawer-close"
                data-testid="mobile-drawer-close"
                aria-label="Close navigation menu"
                onclick="window.aioDrawer && window.aioDrawer.close()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="6" y1="6" x2="18" y2="18"></line>
                <line x1="18" y1="6" x2="6" y2="18"></line>
            </svg>
        </button>
        <div class="brand">
            @include('partials.logo', ['variant' => 'dark', 'height' => 40, 'testid' => 'brand-logo-drawer'])
        </div>
        <a href="{{ route('ambassador.dashboard') }}" data-testid="drawer-nav-dashboard"
           onclick="window.aioDrawer && window.aioDrawer.close()">My Rewards</a>
        <a href="{{ route('ambassador.milestones') }}" data-testid="drawer-nav-milestones"
           onclick="window.aioDrawer && window.aioDrawer.close()">Reward Milestones</a>
        <a href="{{ route('ambassador.referrals') }}" data-testid="drawer-nav-referrals"
           onclick="window.aioDrawer && window.aioDrawer.close()">My Referrals</a>
        <a href="{{ route('ambassador.rewards.history') }}" data-testid="drawer-nav-history"
           onclick="window.aioDrawer && window.aioDrawer.close()">Reward History</a>
        <a href="{{ route('ambassador.payout-settings') }}" data-testid="drawer-nav-payout-settings"
           onclick="window.aioDrawer && window.aioDrawer.close()">Payout Settings</a>
        <a href="{{ route('ambassador.security') }}" data-testid="drawer-nav-security"
           onclick="window.aioDrawer && window.aioDrawer.close()">Account Security</a>
        @if (auth()->user()?->hasAnyRole(\App\Enums\Role::panelRoles()))
            <a href="/admin" data-testid="drawer-nav-admin-access" style="color:#fef3c7"
               onclick="window.aioDrawer && window.aioDrawer.close()">Admin Access</a>
        @endif
        <div class="divider"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" data-testid="drawer-nav-logout">Sign out</button>
        </form>
    </nav>

    <div class="shell">
        {{-- Desktop / tablet sidebar (hidden on mobile via CSS) --}}
        <aside class="sidebar" data-testid="member-sidebar">
            <div class="brand" data-testid="ambassador-brand">
                @include('partials.logo', ['variant' => 'dark', 'height' => 42, 'testid' => 'brand-logo-member'])
            </div>
            <a href="{{ route('ambassador.dashboard') }}" data-testid="nav-dashboard">My Rewards</a>
            <a href="{{ route('ambassador.milestones') }}" data-testid="nav-milestones">Reward Milestones</a>
            <a href="{{ route('ambassador.referrals') }}" data-testid="nav-referrals">My Referrals</a>
            <a href="{{ route('ambassador.rewards.history') }}" data-testid="nav-history">Reward History</a>
            <a href="{{ route('ambassador.payout-settings') }}" data-testid="nav-payout-settings">Payout Settings</a>
            <a href="{{ route('ambassador.security') }}" data-testid="nav-security">Account Security</a>
            @if (auth()->user()?->hasAnyRole(\App\Enums\Role::panelRoles()))
                <a href="/admin" data-testid="nav-admin-access" style="color:#fef3c7">Admin Access</a>
            @endif
            <div class="divider"></div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" data-testid="nav-logout">Sign out</button>
            </form>
        </aside>
        <main>
            {{-- Livewire full-page components (#[Layout]) render into $slot;
                 classic Blade @extends pages use @section('content'). --}}
            @isset($slot)
                {{ $slot }}
            @else
                @yield('content')
            @endif
        </main>
    </div>

    <script>
        // Minimal, dependency-free drawer controller. Handles open/close,
        // Escape key, body scroll lock and focus restoration.
        (function () {
            var body = document.body;
            var drawer = document.getElementById('member-drawer');
            var hamburger = document.querySelector('[data-testid="mobile-hamburger"]');
            var lastFocus = null;
            function open() {
                lastFocus = document.activeElement;
                body.classList.add('drawer-open');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'true');
                if (drawer) drawer.focus();
            }
            function close() {
                body.classList.remove('drawer-open');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
                if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && body.classList.contains('drawer-open')) close();
            });
            window.aioDrawer = { open: open, close: close };
        })();
    </script>
    @livewireScripts
    @stack('scripts')
</body>
</html>
