@extends('layouts.public')
@section('title', 'AIO Media packages')
@section('content')
    <h1 style="font-size:1.75rem;margin:0 0 .5rem">AIO Media packages</h1>
    <p style="color:#475569;max-width:640px;margin:0 0 2rem">Choose a package, enter your delivery details, then pay securely with Stripe.</p>

    @if ($packages->isEmpty())
        <p data-testid="pkg-empty" style="color:#64748b">No packages are available right now.</p>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem" data-testid="pkg-list">
        @foreach ($packages as $pkg)
            <div data-testid="pkg-card" style="background:#fff;border-radius:.75rem;padding:1.5rem;box-shadow:0 1px 3px rgba(15,23,42,.08);display:flex;flex-direction:column">
                <h2 data-testid="pkg-name" style="margin:0 0 .3rem;font-size:1.15rem">{{ $pkg->name }}</h2>
                <div style="color:#64748b;font-size:.9rem" data-testid="pkg-duration">{{ $pkg->duration_label }}</div>
                <div style="margin:.7rem 0;font-size:1.8rem;font-weight:700" data-testid="pkg-price">{{ $pkg->priceFormatted() }}</div>
                <p style="color:#475569;flex:1;margin:.4rem 0 1rem" data-testid="pkg-blurb">{{ $pkg->short_description }}</p>
                @if ($pkg->includes_vpn)
                    <span data-testid="pkg-vpn-badge" style="display:inline-block;background:#e0f2fe;color:#075985;padding:.15rem .6rem;border-radius:999px;font-size:.75rem;font-weight:600;margin-bottom:.75rem;align-self:flex-start">VPN included</span>
                @endif
                <a href="{{ route('checkout.details', ['slug' => $pkg->slug]) }}"
                   data-testid="pkg-choose"
                   style="padding:.7rem 1rem;background:#0f172a;color:#fff;border-radius:.5rem;font-weight:600;text-decoration:none;text-align:center">
                    Choose package
                </a>
            </div>
        @endforeach
    </div>
@endsection
