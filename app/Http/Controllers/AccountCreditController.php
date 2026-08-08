<?php

namespace App\Http\Controllers;

use App\Domain\Credits\AccountCreditLedger;
use App\Models\AccountCreditReservation;
use App\Models\AccountCreditTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountCreditController extends Controller
{
    public function show(Request $request, AccountCreditLedger $ledger): View
    {
        $user = $request->user();
        abort_unless($user && $user->ambassadorProfile, 403);
        abort_unless((bool) $user->is_active, 403);

        $profile = $user->ambassadorProfile;
        $balanceMinor = $ledger->balanceMinor($profile);
        $reservedMinor = $ledger->reservedMinor($profile);
        $availableMinor = $ledger->availableMinor($profile);

        $transactions = AccountCreditTransaction::query()
            ->where('ambassador_profile_id', $profile->id)
            ->with(['reward', 'purchase'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $reservations = AccountCreditReservation::query()
            ->where('ambassador_profile_id', $profile->id)
            ->where('status', AccountCreditReservation::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get();

        return view('ambassador.account-credit', [
            'balanceMinor' => $balanceMinor,
            'balanceFormatted' => '£'.number_format($balanceMinor / 100, 2),
            'reservedMinor' => $reservedMinor,
            'reservedFormatted' => '£'.number_format($reservedMinor / 100, 2),
            'availableMinor' => $availableMinor,
            'availableFormatted' => '£'.number_format($availableMinor / 100, 2),
            'transactions' => $transactions,
            'reservations' => $reservations,
            'redemptionEnabled' => true,
        ]);
    }
}
