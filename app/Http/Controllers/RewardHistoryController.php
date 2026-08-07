<?php

namespace App\Http\Controllers;

use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RewardHistoryController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $profile = $user->ambassadorProfile;
        abort_unless($profile, 403);

        $rewards = Reward::query()
            ->where('ambassador_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('ambassador.rewards.history', [
            'rewards' => $rewards,
        ]);
    }
}
