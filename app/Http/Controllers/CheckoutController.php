<?php

namespace App\Http\Controllers;

use App\Domain\Billing\StripeCheckoutService;
use App\Domain\Credits\AccountCreditCheckoutService;
use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Credits\AccountCreditReservationService;
use App\Domain\Referrals\AttributionCookie;
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

    public function review(string $slug, AccountCreditLedger $ledger): View|RedirectResponse
    {
        $pkg = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $details = Session::get('checkout.details');
        if (! $details || ($details['package_slug'] ?? null) !== $slug) {
            return redirect()->route('checkout.details', ['slug' => $slug]);
        }

        $creditAvailableMinor = 0;
        $canUseCredit = false;
        $user = auth()->user();
        if ($user?->ambassadorProfile && $user->is_active) {
            $creditAvailableMinor = $ledger->availableMinor($user->ambassadorProfile);
            $canUseCredit = $creditAvailableMinor > 0;
        }

        return view('checkout.review', [
            'package' => $pkg,
            'details' => $details,
            'canUseCredit' => $canUseCredit,
            'creditAvailableMinor' => $creditAvailableMinor,
            'creditAvailableFormatted' => '£'.number_format($creditAvailableMinor / 100, 2),
        ]);
    }

    public function pay(
        Request $request,
        string $slug,
        StripeCheckoutService $stripe,
        AccountCreditCheckoutService $creditCheckout,
    ): RedirectResponse {
        $pkg = Package::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $details = Session::get('checkout.details');
        if (! $details || ($details['package_slug'] ?? null) !== $slug) {
            return redirect()->route('checkout.details', ['slug' => $slug]);
        }

        // Opt-in only — never trust client-submitted credit amounts.
        $useCredit = $request->boolean('use_account_credit');

        // Prevent duplicate pending purchases: reuse recent pending row with matching key.
        $recent = Purchase::where('buyer_email', $details['buyer_email'])
            ->where('package_id', $pkg->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()->first();

        // Attribution: encrypted + validated cookie set by /r/{code}.
        $attrCode = null;
        $attrId = null;
        $ambId = null;
        $payload = app(AttributionCookie::class)->read($request);
        if ($payload) {
            $attrCode = $payload['code'];
            $attrId = $payload['attribution_id'];
            $ambId = app(AttributionCookie::class)->ambassadorProfileId($payload);
        }

        // Anti-farming: never attribute a purchase to the same person (email match).
        if ($ambId && $this->isSelfReferral($ambId, $details['buyer_email'], $request->user()?->ambassadorProfile)) {
            $attrCode = null;
            $attrId = null;
            $ambId = null;
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

        $memberProfile = $request->user()?->ambassadorProfile;
        $wantsCredit = $useCredit && $memberProfile && $request->user()?->is_active;

        if ($wantsCredit) {
            try {
                $result = $creditCheckout->beginCheckout(
                    purchase: $purchase,
                    package: $pkg,
                    profile: $memberProfile,
                    useCredit: true,
                    request: $request,
                    actor: $request->user(),
                );
            } catch (\InvalidArgumentException $e) {
                return redirect()->route('checkout.review', ['slug' => $slug])
                    ->withErrors(['credit' => $e->getMessage()]);
            } catch (\RuntimeException $e) {
                return redirect()->route('checkout.review', ['slug' => $slug])
                    ->withErrors(['stripe' => $e->getMessage()]);
            }

            if ($result['fully_credited']) {
                Session::forget('checkout.details');

                return redirect()->route('checkout.success', ['purchase' => $result['purchase']->id]);
            }

            return redirect()->away($result['stripe_session']->url);
        }

        // Standard Stripe-only path (no credit).
        $purchase->update([
            'account_credit_applied_minor' => 0,
            'external_amount_minor' => $purchase->amount_minor,
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
        if (! $purchase && $request->query('purchase')) {
            $purchase = Purchase::find($request->query('purchase'));
        }

        return view('checkout.success', ['purchase' => $purchase]);
    }

    public function cancel(Request $request, AccountCreditReservationService $reservations): View
    {
        $purchase = Purchase::find($request->query('purchase'));
        if ($purchase && $purchase->status === 'pending') {
            $reservations->releaseForPurchase($purchase, $request->user(), 'cancelled');
        }

        return view('checkout.cancel', ['purchase' => $purchase]);
    }

    private function isSelfReferral(int $ambassadorProfileId, string $buyerEmail, ?AmbassadorProfile $loggedInProfile): bool
    {
        if ($loggedInProfile && (int) $loggedInProfile->id === $ambassadorProfileId) {
            return true;
        }

        $profile = AmbassadorProfile::query()->with('user')->find($ambassadorProfileId);
        $email = $profile?->user?->email;
        if ($email && strcasecmp($email, $buyerEmail) === 0) {
            return true;
        }

        return false;
    }
}
