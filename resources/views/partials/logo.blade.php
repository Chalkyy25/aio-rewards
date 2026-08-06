{{--
    AIO Media logo partial.

    Swap strategy (spec: sync to actual theme toggle, NOT OS preference):
    - Both PNGs are rendered inline. CSS shows the light-text variant by
      default and switches to the dark-text variant whenever any ancestor
      element carries the `.dark` class. Filament sets `class="dark"` on
      the <html> element when its theme toggle fires, so this stays in
      sync with the actual toggle rather than the visitor's OS setting.
    - No `<picture media="(prefers-color-scheme: dark)">` — we're not
      driving off OS prefs.

    Variants:
      $variant = 'auto'  → class-based swap via `.dark` (default).
      $variant = 'dark'  → force the DARK-THEME logo (white text) — use on
                            permanently-dark surfaces such as the member
                            sidebar.
      $variant = 'light' → force the LIGHT-THEME logo (black text) — use on
                            permanently-light surfaces.
      $height (int, default 40) — pixel height for the img.
      $link (bool, default true) — wrap in an <a> to /.
--}}
@props([
    'variant' => 'auto',
    'height' => 40,
    'link' => true,
    'testid' => 'brand-logo',
])

@php
    $lightSrc  = asset('images/aio-media-logo-light.png');
    $darkSrc   = asset('images/aio-media-logo-dark.png');
    $brandName = function_exists('settings') ? (settings('brand.name') ?: config('app.name')) : config('app.name');
    $alt       = $brandName.' — AIO Media';
@endphp

@once
    {{-- Emit the CSS inline the first time the partial is used on this
         response. `@push('head')` isn't reliable when the partial is
         included from a Livewire component or after @stack('head') has
         already been rendered, so we ship it inline. --}}
    <style data-testid="aio-logo-style">
        /*
         * Class-based light/dark logo swap. `<html class="dark">` (Filament's
         * dark-mode signal) or `<body class="dark">` flips the visible variant.
         */
        .aio-logo{display:inline-flex;align-items:center;line-height:0;text-decoration:none}
        .aio-logo img{display:block;width:auto}
        .aio-logo .aio-logo__dark{display:none}
        .aio-logo .aio-logo__light{display:block}
        html.dark .aio-logo .aio-logo__light,
        body.dark .aio-logo .aio-logo__light,
        .dark .aio-logo .aio-logo__light{display:none}
        html.dark .aio-logo .aio-logo__dark,
        body.dark .aio-logo .aio-logo__dark,
        .dark .aio-logo .aio-logo__dark{display:block}
    </style>
@endonce

@if($link)
    <a href="{{ route('home') }}" class="aio-logo" data-testid="{{ $testid }}" aria-label="{{ $alt }}">
@else
    <span class="aio-logo" data-testid="{{ $testid }}">
@endif

@switch($variant)
    @case('dark')
        {{-- Permanently dark surface (e.g. member sidebar) → only the white-text logo. --}}
        <img src="{{ $darkSrc }}" alt="{{ $alt }}" height="{{ $height }}"
             style="height:{{ $height }}px" class="aio-logo__forced"
             data-testid="{{ $testid }}-img" data-theme="dark">
        @break
    @case('light')
        {{-- Permanently light surface → only the black-text logo. --}}
        <img src="{{ $lightSrc }}" alt="{{ $alt }}" height="{{ $height }}"
             style="height:{{ $height }}px" class="aio-logo__forced"
             data-testid="{{ $testid }}-img" data-theme="light">
        @break
    @default
        {{-- Auto: both rendered, CSS hides one based on the .dark class. --}}
        <img src="{{ $lightSrc }}" alt="{{ $alt }}" height="{{ $height }}"
             style="height:{{ $height }}px" class="aio-logo__light"
             data-testid="{{ $testid }}-img-light" data-theme="light">
        <img src="{{ $darkSrc }}" alt="{{ $alt }}" height="{{ $height }}"
             style="height:{{ $height }}px" class="aio-logo__dark"
             data-testid="{{ $testid }}-img-dark" data-theme="dark">
@endswitch

@if($link)
    </a>
@else
    </span>
@endif
