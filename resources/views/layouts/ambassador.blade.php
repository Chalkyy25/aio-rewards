<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Ambassador — ' . config('app.name'))</title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; }
        html, body { margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif;
                     background: #f5f5f4; color: #111; }
        .shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        aside { background: #0f172a; color: #e2e8f0; padding: 1.5rem; }
        aside a, aside button { color: #cbd5e1; display: block; padding: .4rem 0;
                                text-decoration: none; background: none; border: 0;
                                text-align: left; font: inherit; cursor: pointer; width:100%; }
        aside a:hover, aside button:hover { color: #fff; }
        aside .brand { color: #fff; font-weight: 700; margin-bottom: 1.5rem; letter-spacing: -0.01em; }
        aside .divider { border-top: 1px solid #1e293b; margin: 1rem 0; }
        main { padding: 2rem; max-width: 1080px; }
        @media (max-width: 720px) {
            .shell { grid-template-columns: 1fr; }
            aside { display: flex; align-items: center; justify-content: space-between; padding: 1rem; }
            aside .divider, aside .brand + a { display: none; }
        }
    </style>
    @stack('head')
    @livewireStyles
</head>
<body>
<div class="shell">
    <aside>
        <div class="brand" data-testid="ambassador-brand">{{ config('app.name') }}</div>
        <a href="{{ route('ambassador.dashboard') }}" data-testid="nav-dashboard">Dashboard</a>
        <div class="divider"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" data-testid="nav-logout">Sign out</button>
        </form>
    </aside>
    <main>
        @yield('content')
    </main>
</div>
@livewireScripts
@stack('scripts')
</body>
</html>
