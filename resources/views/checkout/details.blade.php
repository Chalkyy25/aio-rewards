@extends('layouts.public')
@section('title', 'Checkout — your details')
@push('head')<style>
    .card{max-width:640px;margin:2rem auto;background:#fff;border-radius:1rem;padding:2rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)}
    label{display:block;margin:.9rem 0 .3rem;font-weight:500}
    input,select{width:100%;padding:.7rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:1rem;box-sizing:border-box}
    input[type=checkbox]{width:auto;padding:0;margin-right:.5rem;accent-color:#0f172a}
    label.consent{display:flex;align-items:center;gap:.5rem;font-weight:400;margin:.5rem 0}
    small.help{color:#64748b;display:block;margin-top:.25rem}
    .btn{margin-top:1rem;padding:.9rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer;width:100%}
    .err{background:#fef2f2;color:#991b1b;padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem}
    .field-err{color:#991b1b;font-size:.85rem;margin-top:.2rem}
</style>@endpush
@section('content')
<div class="card">
    <h1 style="margin:0 0 .3rem">Your details</h1>
    <p style="color:#64748b;margin:0 0 1.5rem">Package: <strong>{{ $package->name }}</strong> · {{ $package->priceFormatted() }}</p>
    @if ($errors->any())<div class="err" data-testid="chk-details-error">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('checkout.details', ['slug' => $package->slug]) }}" data-testid="chk-details-form">@csrf
        <label>Full name</label>
        <input name="buyer_name" data-testid="chk-name" value="{{ old('buyer_name', $prefill['buyer_name'] ?? '') }}" required>
        @error('buyer_name')<div class="field-err">{{ $message }}</div>@enderror

        <label>Email address</label>
        <input type="email" name="buyer_email" data-testid="chk-email" value="{{ old('buyer_email', $prefill['buyer_email'] ?? '') }}" required>
        @error('buyer_email')<div class="field-err">{{ $message }}</div>@enderror

        <label>Preferred AIO Media username</label>
        <input name="preferred_username" data-testid="chk-username" value="{{ old('preferred_username', $prefill['preferred_username'] ?? '') }}" required>
        <small class="help">We'll use this username where available. If it is already taken, we may make a small adjustment.</small>
        @error('preferred_username')<div class="field-err" data-testid="err-username">{{ $message }}</div>@enderror

        <label>Preferred delivery method</label>
        <select name="delivery_method" data-testid="chk-delivery" required>
            <option value="whatsapp" @selected(old('delivery_method',$prefill['delivery_method']??'')==='whatsapp')>WhatsApp</option>
            <option value="email" @selected(old('delivery_method',$prefill['delivery_method']??'email')==='email')>Email</option>
            <option value="telegram" @selected(old('delivery_method',$prefill['delivery_method']??'')==='telegram')>Telegram</option>
        </select>

        <label>WhatsApp / mobile number</label>
        <input name="buyer_phone" data-testid="chk-phone" value="{{ old('buyer_phone', $prefill['buyer_phone'] ?? '') }}">
        @error('buyer_phone')<div class="field-err" data-testid="err-phone">{{ $message }}</div>@enderror

        <label>Telegram username (optional unless delivery is Telegram)</label>
        <input name="buyer_telegram" data-testid="chk-telegram" value="{{ old('buyer_telegram', $prefill['buyer_telegram'] ?? '') }}">
        @error('buyer_telegram')<div class="field-err" data-testid="err-telegram">{{ $message }}</div>@enderror

        <label class="consent">
            <input type="checkbox" name="terms" value="1" data-testid="chk-terms" required> I accept the terms.
        </label>
        <label class="consent">
            <input type="checkbox" name="privacy" value="1" data-testid="chk-privacy" required> I accept the privacy notice.
        </label>

        <button class="btn" type="submit" data-testid="chk-submit">Review order</button>
    </form>
</div>
@endsection
