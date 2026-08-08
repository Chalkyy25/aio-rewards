@extends('layouts.public')
@section('title', 'Review your order')
@section('content')
@php
    $fmt = static fn (int $minor): string => '£'.number_format($minor / 100, 2);
    $with = $quoteWithCredit ?? ['credit_applied_minor' => 0, 'external_amount_minor' => $package->amount_minor];
    $without = $quoteWithout ?? ['credit_applied_minor' => 0, 'external_amount_minor' => $package->amount_minor];
@endphp
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
        @php $refName = \App\Support\ReferralContext::referringName(); @endphp
        <div data-testid="review-referral-applied" style="margin:1rem 0;background:#dcfce7;color:#065f46;padding:.6rem 1rem;border-radius:.5rem">
            @if ($refName)
                Referral applied — thanks to <strong data-testid="review-referral-name">{{ $refName }}</strong>.
            @else
                Referral applied.
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('checkout.pay', ['slug' => $package->slug]) }}" style="margin-top:1.5rem" id="checkout-review-form">
        @csrf

        <div data-testid="review-payment-breakdown"
             style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem;margin-bottom:1.25rem">
            <div style="font-weight:600;margin-bottom:.75rem">Payment summary</div>
            <div style="display:flex;justify-content:space-between;margin-bottom:.35rem">
                <span>Package</span>
                <span data-testid="review-breakdown-package">{{ $package->priceFormatted() }}</span>
            </div>
            <div id="review-credit-line" style="display:none;justify-content:space-between;margin-bottom:.35rem;color:#047857">
                <span>Account Credit</span>
                <span data-testid="review-breakdown-credit">−{{ $fmt((int) $with['credit_applied_minor']) }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid #e2e8f0;padding-top:.5rem;margin-top:.5rem">
                <span>Amount to pay by card</span>
                <span data-testid="review-breakdown-card"
                      data-with-credit="{{ $fmt((int) $with['external_amount_minor']) }}"
                      data-without-credit="{{ $fmt((int) $without['external_amount_minor']) }}">
                    {{ $fmt((int) $without['external_amount_minor']) }}
                </span>
            </div>
        </div>

        @if ($canUseCredit ?? false)
            <div data-testid="review-account-credit" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem;margin-bottom:1.25rem">
                <div style="font-weight:600;margin-bottom:.35rem">Account Credit available</div>
                <div data-testid="review-credit-available" style="font-size:1.25rem;font-weight:700;margin-bottom:.75rem">{{ $creditAvailableFormatted }}</div>
                <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="use_account_credit" value="1" data-testid="review-use-credit"
                           style="margin-top:.2rem" id="review-use-credit">
                    <span>
                        Use Account Credit
                        <span style="display:block;color:#64748b;font-size:.85rem;margin-top:.2rem">
                            Optional. Credit applied is calculated on the server and never exceeds the package price.
                        </span>
                    </span>
                </label>
            </div>
        @endif

        <div style="display:flex;gap:.5rem">
            <a href="{{ route('checkout.details', ['slug' => $package->slug]) }}" data-testid="review-edit"
               style="padding:.85rem 1rem;background:#fff;border:1px solid #cbd5e1;color:#0f172a;border-radius:.5rem;text-decoration:none;font-weight:600">Edit details</a>
            <button type="submit" data-testid="review-pay" style="flex:1;padding:.9rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer">
                Continue to payment
            </button>
        </div>
    </form>
</div>
<script>
(function () {
    var box = document.getElementById('review-use-credit');
    var line = document.getElementById('review-credit-line');
    var card = document.querySelector('[data-testid="review-breakdown-card"]');
    if (!box || !card) return;
    function sync() {
        var on = box.checked;
        if (line) line.style.display = on ? 'flex' : 'none';
        card.textContent = on ? card.getAttribute('data-with-credit') : card.getAttribute('data-without-credit');
    }
    box.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
