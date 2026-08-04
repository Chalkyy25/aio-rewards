@extends('layouts.public')
@section('title', 'Review your order')
@section('content')
<div style="max-width:640px;margin:2rem auto;background:#fff;border-radius:1rem;padding:2rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)">
    <h1 style="margin:0 0 1rem">Review your order</h1>
    @if ($errors->any())<div style="background:#fef2f2;color:#991b1b;padding:.75rem 1rem;border-radius:.5rem" data-testid="review-error">{{ $errors->first() }}</div>@endif

    <dl style="margin:0" data-testid="review-summary">
        <dt style="color:#64748b;font-size:.85rem">Package</dt><dd style="margin:0 0 .8rem;font-weight:600">{{ $package->name }} — {{ $package->duration_label }}</dd>
        <dt style="color:#64748b;font-size:.85rem">Price</dt><dd style="margin:0 0 .8rem;font-weight:600" data-testid="review-price">{{ $package->priceFormatted() }}</dd>
        <dt style="color:#64748b;font-size:.85rem">Full name</dt><dd style="margin:0 0 .8rem">{{ $details['buyer_name'] }}</dd>
        <dt style="color:#64748b;font-size:.85rem">Preferred username</dt><dd style="margin:0 0 .8rem" data-testid="review-username">{{ $details['preferred_username'] }}</dd>
        <dt style="color:#64748b;font-size:.85rem">Delivery method</dt><dd style="margin:0 0 .8rem">{{ ucfirst($details['delivery_method']) }}</dd>
        <dt style="color:#64748b;font-size:.85rem">Email</dt><dd style="margin:0 0 .8rem">{{ $details['buyer_email'] }}</dd>
        @if(!empty($details['buyer_phone']))<dt style="color:#64748b;font-size:.85rem">WhatsApp/Mobile</dt><dd style="margin:0 0 .8rem">{{ $details['buyer_phone'] }}</dd>@endif
        @if(!empty($details['buyer_telegram']))<dt style="color:#64748b;font-size:.85rem">Telegram</dt><dd style="margin:0 0 .8rem">{{ $details['buyer_telegram'] }}</dd>@endif
    </dl>

    @if (request()->cookie(config('referrals.cookie.name', 'aior_ref')))
        <div data-testid="review-referral-applied" style="margin:1rem 0;background:#dcfce7;color:#065f46;padding:.6rem 1rem;border-radius:.5rem">Referral applied.</div>
    @endif

    <div style="display:flex;gap:.5rem;margin-top:1.5rem">
        <a href="{{ route('checkout.details', ['slug' => $package->slug]) }}" data-testid="review-edit"
           style="padding:.85rem 1rem;background:#fff;border:1px solid #cbd5e1;color:#0f172a;border-radius:.5rem;text-decoration:none;font-weight:600">Edit details</a>
        <form method="POST" action="{{ route('checkout.pay', ['slug' => $package->slug]) }}" style="flex:1">@csrf
            <button type="submit" data-testid="review-pay" style="width:100%;padding:.9rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer">
                Pay securely with Stripe
            </button>
        </form>
    </div>
</div>
@endsection
