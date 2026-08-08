<?php

namespace App\Http\Controllers;

use App\Domain\Credits\AccountCreditLedger;
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
        $transactions = AccountCreditTransaction::query()
            ->where('ambassador_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('ambassador.account-credit', [
            'balanceMinor' => $balanceMinor,
            'balanceFormatted' => '£'.number_format($balanceMinor / 100, 2),
            'transactions' => $transactions,
            'redemptionEnabled' => false,
        ]);
    }
}
