@extends('layouts.public')
@section('title', 'Payment cancelled')
@section('content')
<div style="max-width:640px;margin:2rem auto;background:#fff;border-radius:1rem;padding:2rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)" data-testid="cancel-card">
    <h1 style="margin:0 0 .6rem">Payment was not completed</h1>
    <p style="color:#475569">Your order is still saved. You can try again or edit your details.</p>
    @if ($purchase && $purchase->package)
        <a href="{{ route('checkout.review', ['slug' => $purchase->package->slug]) }}" data-testid="cancel-retry"
           style="display:inline-block;margin-top:1rem;padding:.7rem 1.2rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600">Try again</a>
    @endif
    <a href="{{ route('packages') }}" data-testid="cancel-packages" style="display:inline-block;margin-top:1rem;padding:.7rem 1.2rem;color:#0f172a;text-decoration:underline;font-weight:500">Back to packages</a>
</div>
@endsection
