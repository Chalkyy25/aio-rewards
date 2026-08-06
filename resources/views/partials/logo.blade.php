{{--
    AIO Media logo partial.
    Variants:
      $variant = 'auto'  → uses <picture> so the browser picks the right
                            variant based on the user's OS/browser
                            prefers-color-scheme setting.
      $variant = 'dark'  → force the DARK-THEME logo (white text) — use on
                            dark backgrounds (e.g. member sidebar).
      $variant = 'light' → force the LIGHT-THEME logo (black text) — use on
                            light backgrounds.
      $height (int, optional, default 40) — pixel height for the img.
      $link (bool, optional, default true) — wrap the logo in a link to /.
--}}
@props([
    'variant' => 'auto',
    'height' => 40,
    'link' => true,
    'testid' => 'brand-logo',
])

@php
    $lightSrc = asset('images/aio-media-logo-light.png');
    $darkSrc  = asset('images/aio-media-logo-dark.png');
    // Use the admin-editable brand name so a Super Admin can retitle the
    // brand from Settings without shipping a new asset.
    $brandName = function_exists('settings') ? (settings('brand.name') ?: config('app.name')) : config('app.name');
    $alt      = $brandName . ' — AIO Media';
@endphp

@if($link)
    <a href="{{ route('home') }}" data-testid="{{ $testid }}" style="display:inline-flex;align-items:center;line-height:0" aria-label="{{ $alt }}">
@endif

@switch($variant)
    @case('dark')
        <img src="{{ $darkSrc }}" alt="{{ $alt }}" height="{{ $height }}"
             style="height:{{ $height }}px;width:auto;display:block"
             data-testid="{{ $testid }}-img" data-theme="dark">
        @break
    @case('light')
        <img src="{{ $lightSrc }}" alt="{{ $alt }}" height="{{ $height }}"
             style="height:{{ $height }}px;width:auto;display:block"
             data-testid="{{ $testid }}-img" data-theme="light">
        @break
    @default
        <picture data-testid="{{ $testid }}-picture">
            <source srcset="{{ $darkSrc }}" media="(prefers-color-scheme: dark)">
            <img src="{{ $lightSrc }}" alt="{{ $alt }}" height="{{ $height }}"
                 style="height:{{ $height }}px;width:auto;display:block"
                 data-testid="{{ $testid }}-img" data-theme="auto">
        </picture>
@endswitch

@if($link)
    </a>
@endif
