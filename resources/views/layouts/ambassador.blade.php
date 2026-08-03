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
        html, body { margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; background: #f5f5f4; color: #111; }
        .shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        aside { background: #0f172a; color: #e2e8f0; padding: 1.5rem; }
        aside a { color: #cbd5e1; display: block; padding: .35rem 0; text-decoration: none; }
        aside a:hover { color: #fff; }
        aside .brand { color: #fff; font-weight: 700; margin-bottom: 1.5rem; }
        main { padding: 2rem; }
    </style>

    @stack('head')
    @livewireStyles
</head>
<body>
    <div class="shell">
        <aside>
            <div class="brand">{{ config('app.name') }}</div>
            <nav>
                <a href="{{ route('ambassador.dashboard') }}">Dashboard</a>
                {{-- Sign-out route is added in Phase 1 alongside the auth scaffold. --}}
            </nav>
        </aside>

        <main>
            @yield('content')
        </main>
    </div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
