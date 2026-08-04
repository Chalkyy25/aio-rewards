<?php

namespace App\Http\Controllers;

use App\Domain\Billing\StripeCheckoutService;
use App\Models\AmbassadorProfile;
use App\Models\Package;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function details(Request $request, string $slug): View|RedirectResponse
    {
        $pkg = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $prefill = Session::get('checkout.details', []);

        return view('checkout.details', ['package' => $pkg, 'prefill' => $prefill]);
    }

    public function submitDetails(Request $request, string $slug): RedirectResponse
    {
        $pkg = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:190'],
            'buyer_email' => ['required', 'email:rfc', 'max:190'],
            'preferred_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'delivery_method' => ['required', 'in:whatsapp,email,telegram'],
            'buyer_phone' => ['nullable', 'string', 'max:32', 'required_if:delivery_method,whatsapp'],
            'buyer_telegram' => ['nullable', 'string', 'max:64', 'required_if:delivery_method,telegram'],
            'terms' => ['accepted'],
            'privacy' => ['accepted'],
        ], [
            'preferred_username.regex' => 'Only letters, numbers, underscore and hyphen are allowed.',
            'buyer_phone.required_if' => 'A WhatsApp/mobile number is required for WhatsApp delivery.',
            'buyer_telegram.required_if' => 'A Telegram username is required for Telegram delivery.',
        ]);

        Session::put('checkout.details', array_merge($data, ['package_slug' => $slug]));

        return redirect()->route('checkout.review', ['slug' => $slug]);
    }

    public function review(string $slug): View|RedirectResponse
    {
        $pkg = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $details = Session::get('checkout.details');
        if (! $details || ($details['package_slug'] ?? null) !== $slug) {
            return redirect()->route('checkout.details', ['slug' => $slug]);
        }

        return view('checkout.review', ['package' => $pkg, 'details' => $details]);
    }

    public function pay(Request $request, string $slug, StripeCheckoutService $stripe): RedirectResponse
    {
        $pkg = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $details = Session::get('checkout.details');
        if (! $details || ($details['package_slug'] ?? null) !== $slug) {
            return redirect()->route('checkout.details', ['slug' => $slug]);
        }

        // Prevent duplicate pending purchases: reuse recent pending row with matching key.
        $recentKey = md5($details['buyer_email'].'|'.$pkg->id.'|'.($details['preferred_username'] ?? ''));
        $recent = Purchase::where('buyer_email', $details['buyer_email'])
            ->where('package_id', $pkg->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()->first();

        // Attribution: read cookie set by /r/{code} route.
        $attrCode = null;
        $attrId = null;
        $ambId = null;
        $raw = $request->cookie(config('referrals.cookie.name', 'aior_ref'));
        if (is_string($raw) && $raw !== '') {
            $payload = json_decode($raw, true);
            if (is_array($payload)) {
                $attrCode = $payload['code'] ?? null;
                $attrId = $payload['attribution_id'] ?? null;
                if ($attrCode) {
                    $ambId = AmbassadorProfile::where('referral_code', $attrCode)->value('id');
                }
            }
        }

        $purchase = $recent ?: Purchase::create([
            'package_id' => $pkg->id,
            'buyer_name' => $details['buyer_name'],
            'buyer_email' => $details['buyer_email'],
            'preferred_username' => $details['preferred_username'],
            'buyer_phone' => $details['buyer_phone'] ?? null,
            'buyer_telegram' => $details['buyer_telegram'] ?? null,
            'delivery_method' => $details['delivery_method'],
            'amount_minor' => $pkg->amount_minor,
            'currency' => $pkg->currency,
            'status' => 'pending',
            'attribution_id' => $attrId,
            'referral_code_snapshot' => $attrCode,
            'ambassador_profile_id_snapshot' => $ambId,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);

        if (! StripeCheckoutService::isConfigured()) {
            return redirect()->route('checkout.details', ['slug' => $slug])
                ->withErrors(['stripe' => 'Stripe is not configured on this environment. Please contact the administrator.']);
        }

        $session = $stripe->createSession($purchase, $pkg, $request);
        $purchase->update(['stripe_session_id' => $session->id]);

        return redirect()->away($session->url);
    }

    public function success(Request $request): View
    {
        $purchase = null;
        if ($sessionId = $request->query('session_id')) {
            $purchase = Purchase::where('stripe_session_id', $sessionId)->first();
        }

        return view('checkout.success', ['purchase' => $purchase]);
    }

    public function cancel(Request $request): View
    {
        $purchase = Purchase::find($request->query('purchase'));

        return view('checkout.cancel', ['purchase' => $purchase]);
    }
}
