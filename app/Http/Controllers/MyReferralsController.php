<?php

namespace App\Http\Controllers;

use App\Models\ReferralConversion;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyReferralsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $profile = $user->ambassadorProfile;
        abort_unless($profile, 403);

        $conversions = ReferralConversion::query()
            ->where('ambassador_profile_id', $profile->id)
            ->with(['purchase.package', 'purchase'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('ambassador.referrals.index', [
            'conversions' => $conversions,
        ]);
    }
}
