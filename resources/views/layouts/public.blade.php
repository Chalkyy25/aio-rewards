<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name'))</title>

    <meta name="robots" content="index,follow">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    <link rel="icon" type="image/png" href="{{ asset('images/aio-favicon.png') }}?v=2">
    <link rel="apple-touch-icon" href="{{ asset('images/aio-favicon.png') }}?v=2">
    <link rel="shortcut icon" href="{{ asset('images/aio-favicon.png') }}?v=2">

    <style>
        /*
         * We only ship light-theme UI on customer-facing pages, but the
         * <picture> element in partials.logo still swaps to the white-text
         * logo automatically for visitors whose OS/browser is in dark
         * mode — see partials/logo.blade.php.
         */
        :root { color-scheme: light; }
        html, body { margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; background: #fafafa; color: #111; }
        header, main, footer { max-width: 1080px; margin: 0 auto; padding: 1.5rem; }
        header { display: flex; align-items: center; justify-content: space-between; }
        a { color: #0f172a; }
        .brand { font-weight: 700; letter-spacing: -0.01em; }
        footer { border-top: 1px solid #e5e7eb; margin-top: 4rem; color: #64748b; font-size: .9rem; }
    </style>

    @stack('head')
    @livewireStyles
</head>
<body class="h-full">
    <header>
        @include('partials.logo', ['variant' => 'auto', 'height' => 40, 'testid' => 'brand-logo-public'])
        <nav>
            @auth
                <a href="{{ route('ambassador.dashboard') }}">My Rewards</a>
            @endauth
        </nav>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <span data-testid="footer-note">{{ settings('brand.footer_note') }}</span>
    </footer>

    @livewireScripts
    @stack('scripts')
</body>
</html>
