@extends('layouts.public')
@section('title', 'Order '.$purchase->orderReference())
@push('head')
<style>
    .status-wrap{max-width:760px;margin:2rem auto;padding:0 1rem}
    .status-card{background:#fff;border-radius:1rem;padding:2rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)}
    .status-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
    .status-header h1{margin:0;font-size:1.5rem}
    .status-badge{display:inline-block;padding:.3rem .8rem;border-radius:999px;font-weight:600;font-size:.85rem}
    .badge-payment_received{background:#e0f2fe;color:#075985}
    .badge-pending_setup{background:#f1f5f9;color:#334155}
    .badge-in_progress{background:#fef3c7;color:#92400e}
    .badge-awaiting_customer{background:#ddd6fe;color:#5b21b6}
    .badge-completed{background:#dcfce7;color:#065f46}
    .badge-cancelled,.badge-refunded{background:#fee2e2;color:#991b1b}
    .timeline{list-style:none;padding:0;margin:1.2rem 0;border-left:2px solid #e2e8f0}
    .timeline li{position:relative;padding:.35rem 0 .35rem 1.4rem}
    .timeline li:before{content:'';position:absolute;left:-7px;top:.9rem;width:12px;height:12px;border-radius:50%;background:#cbd5e1}
    .timeline li.done:before{background:#0f172a}
    .timeline .label{font-weight:600}
    .timeline .when{color:#64748b;font-size:.85rem;margin-left:.5rem}
    dl.meta{margin:1rem 0;display:grid;grid-template-columns:180px 1fr;gap:.4rem 1rem}
    dl.meta dt{color:#64748b;font-size:.85rem}
    dl.meta dd{margin:0}
    .creds{background:#0f172a;color:#e2e8f0;padding:1.25rem;border-radius:.75rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.95rem}
    .creds .field{display:flex;justify-content:space-between;align-items:center;padding:.35rem 0}
    .creds button{background:#334155;color:#f8fafc;border:0;padding:.25rem .6rem;border-radius:.4rem;cursor:pointer;font-size:.75rem}
    .instructions{background:#f8fafc;border-radius:.75rem;padding:1rem;white-space:pre-wrap;font-size:.95rem;color:#0f172a;margin:.5rem 0}
    .downloads a{display:inline-block;margin:.25rem .5rem .25rem 0;padding:.5rem 1rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:500;font-size:.9rem}
    .note{color:#94a3b8;font-size:.85rem;margin-top:1rem}
</style>
@endpush

@section('content')
@php
    $s = $purchase->fulfilment_status ?: 'payment_received';
    $steps = [
        'payment_received' => 'Payment received',
        'pending_setup' => 'Pending setup',
        'in_progress' => 'Setting up your account',
        'awaiting_customer' => 'Waiting on you',
        'completed' => 'Ready to use',
    ];
    $reached = array_search($s, array_keys($steps), true);
@endphp
<div class="status-wrap" data-testid="order-status-page">
    <div class="status-card">
        <div class="status-header">
            <div>
                <div style="color:#64748b;font-size:.85rem">Order reference</div>
                <h1 data-testid="order-ref">{{ $purchase->orderReference() }}</h1>
            </div>
            <span class="status-badge badge-{{ $s }}" data-testid="order-status-badge">{{ $purchase->statusLabel() }}</span>
        </div>

        <dl class="meta">
            <dt>Package</dt><dd data-testid="order-package">{{ $purchase->package->name }} — {{ $purchase->package->duration_label }}</dd>
            <dt>Amount paid</dt><dd data-testid="order-amount">{{ $purchase->priceFormatted() }}</dd>
            <dt>Payment status</dt><dd>{{ ucfirst($purchase->status) }}</dd>
            <dt>Preferred username</dt><dd>{{ $purchase->preferred_username }}</dd>
            <dt>Delivery method</dt><dd>{{ ucfirst($purchase->delivery_method) }}</dd>
        </dl>

        <h2 style="font-size:1rem;margin:1.2rem 0 .3rem">Progress</h2>
        <ul class="timeline" data-testid="order-timeline">
            @foreach ($steps as $key => $label)
                <li class="{{ $reached !== false && array_search($key, array_keys($steps), true) <= $reached ? 'done' : '' }}" data-step="{{ $key }}">
                    <span class="label">{{ $label }}</span>
                    @php
                        $ts = match($key) {
                            'payment_received' => $purchase->payment_received_at,
                            'pending_setup' => null,
                            'in_progress' => $purchase->setup_started_at,
                            'awaiting_customer' => $purchase->awaiting_customer_at,
                            'completed' => $purchase->completed_at,
                            default => null,
                        };
                    @endphp
                    @if ($ts)<span class="when">{{ $ts->diffForHumans() }}</span>@endif
                </li>
            @endforeach
        </ul>

        @if (in_array($s, ['cancelled','refunded'], true))
            <div style="background:#fee2e2;color:#991b1b;border-radius:.5rem;padding:.75rem 1rem;margin-top:1rem" data-testid="order-cancelled-notice">
                This order has been {{ $s }}. If you believe this is a mistake, please contact support with your order reference.
            </div>
        @endif

        @if ($s === 'completed')
            <h2 style="font-size:1rem;margin:1.5rem 0 .5rem">Your account credentials</h2>
            <div class="creds" data-testid="order-credentials">
                <div class="field">
                    <span>Username</span>
                    <span data-testid="cred-username">{{ $purchase->provisioned_username_enc ?? '—' }}</span>
                </div>
                <div class="field">
                    <span>Password</span>
                    <span data-testid="cred-password">{{ $purchase->provisioned_password_enc ?? '—' }}</span>
                </div>
                @if ($purchase->provisioned_expires_on)
                    <div class="field">
                        <span>Expires on</span>
                        <span data-testid="cred-expires">{{ $purchase->provisioned_expires_on->format('D, j M Y') }}</span>
                    </div>
                @endif
            </div>

            @php
                $instructions = $purchase->setup_instructions_md ?: settings('orders.default_setup_instructions');
            @endphp
            @if ($instructions)
                <h2 style="font-size:1rem;margin:1.2rem 0 .5rem">Setup instructions</h2>
                <div class="instructions" data-testid="order-instructions">{{ $instructions }}</div>
            @endif

            @if (! empty($purchase->download_links))
                <h2 style="font-size:1rem;margin:1.2rem 0 .5rem">Downloads</h2>
                <div class="downloads" data-testid="order-downloads">
                    @foreach ($purchase->download_links as $link)
                        <a href="{{ $link['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer" data-testid="dl-link">{{ $link['label'] ?? $link['url'] ?? 'Download' }}</a>
                    @endforeach
                </div>
            @endif
        @elseif ($s !== 'cancelled' && $s !== 'refunded')
            <p class="note">We do not activate instantly. Your account credentials will appear on this page as soon as an operator marks the order Completed.</p>
        @endif
    </div>
</div>
@endsection
