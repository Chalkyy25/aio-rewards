@extends('layouts.public')
@section('title', 'Payment received — confirming')
@section('content')
<div style="max-width:640px;margin:2rem auto;background:#fff;border-radius:1rem;padding:2.5rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)" data-testid="success-card">
    <h1 style="margin:0 0 .6rem">Thanks! We're confirming your payment.</h1>
    <p style="color:#475569">
        Stripe is confirming your payment. Your AIO Media account details will be delivered by
        <strong>{{ $purchase?->delivery_method ? ucfirst($purchase->delivery_method) : 'your chosen method' }}</strong> after we fulfil your order.
    </p>
    @if ($purchase)
        <dl style="margin:1.5rem 0" data-testid="success-summary">
            <dt style="color:#64748b;font-size:.85rem">Order reference</dt><dd style="margin:0 0 .8rem;font-family:ui-monospace,monospace" data-testid="success-ref">{{ $purchase->orderReference() }}</dd>
            <dt style="color:#64748b;font-size:.85rem">Package</dt><dd style="margin:0 0 .8rem">{{ $purchase->package->name }}</dd>
            <dt style="color:#64748b;font-size:.85rem">Preferred username</dt><dd style="margin:0 0 .8rem" data-testid="success-username">{{ $purchase->preferred_username }}</dd>
            <dt style="color:#64748b;font-size:.85rem">Payment status</dt><dd style="margin:0 0 .8rem" data-testid="success-status">{{ ucfirst($purchase->status) }}</dd>
        </dl>
    @endif
    <p style="color:#94a3b8;font-size:.9rem">We do not activate instantly. You will receive a message with your login details once fulfilment is complete.</p>
    <a href="{{ url()->current().'?'.http_build_query(request()->query()) }}" data-testid="success-refresh"
       style="display:inline-block;margin-top:1rem;padding:.6rem 1.2rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600">Refresh status</a>
</div>
@endsection
