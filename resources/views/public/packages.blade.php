@extends('layouts.public')

@section('title', 'AIO Media packages')

@section('content')
    <section style="max-width:780px">
        <h1 style="font-size:2rem;letter-spacing:-0.02em;margin:0 0 .5rem">AIO Media packages</h1>
        <p style="color:#334155;max-width:640px">
            IPTV, VPN and streaming subscriptions from AIO Media. Direct purchase via Stripe Checkout comes online in the next phase.
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-top:1.75rem">
            @foreach ([
                ['name' => 'IPTV — 12 months', 'blurb' => 'Full IPTV package, annual subscription.'],
                ['name' => 'VPN — 12 months',  'blurb' => 'AIO VPN with global endpoints, annual.'],
                ['name' => 'IPTV + VPN Bundle','blurb' => 'Everything, one price, annual.'],
            ] as $pkg)
                <div data-testid="pkg-card"
                     style="background:#fff;border-radius:.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(15,23,42,.06)">
                    <h2 style="margin:0 0 .4rem;font-size:1.1rem">{{ $pkg['name'] }}</h2>
                    <p style="color:#64748b;font-size:.95rem;margin:0 0 1rem">{{ $pkg['blurb'] }}</p>
                    <button disabled data-testid="pkg-buy-disabled"
                            style="padding:.55rem 1rem;background:#e2e8f0;color:#64748b;border:0;border-radius:.5rem;font-weight:600;cursor:not-allowed">
                        Buy — coming soon
                    </button>
                </div>
            @endforeach
        </div>

        <p style="color:#94a3b8;margin-top:2rem;font-size:.9rem">
            Purchases will open in Phase 3 (Stripe Checkout).
        </p>
    </section>
@endsection
