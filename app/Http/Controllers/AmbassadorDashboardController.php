<?php

namespace App\Http\Controllers;

use App\Domain\Credits\AccountCreditLedger;
use App\Domain\Rewards\MilestoneProgressionService;
use App\Models\ReferralConversion;
use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AmbassadorDashboardController extends Controller
{
    public function __construct(private readonly MilestoneProgressionService $milestones) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $profile = $user?->ambassadorProfile;

        $stats = [
            'approved_conversions' => 0,
            'lifetime_approved_referrals' => 0,
            'pending_reward_minor' => 0,
            'approved_reward_minor' => 0,
            'paid_reward_minor' => 0,
            'lifetime_earned_minor' => 0,
            'available_reward_minor' => 0,
            'progress_current' => 0,
            'progress_target' => null,
            'progress_remaining' => null,
            'progress_next_amount_minor' => null,
            'progress_bonus_amount_minor' => 0,
            'has_available_now' => false,
            'is_max_tier_available' => false,
            'account_credit_balance_minor' => 0,
            'account_credit_available_minor' => 0,
            'account_credit_reserved_minor' => 0,
            'open_claims' => collect(),
            'pending_claim_headline' => 'Pending reward',
            'approved_claim_headline' => 'Approved reward',
            'pending_claim_sub' => 'Awaiting admin approval',
            'approved_claim_sub' => 'Ready for payout',
        ];

        if ($profile) {
            $ledger = app(AccountCreditLedger::class);
            $stats['account_credit_balance_minor'] = $ledger->balanceMinor($profile);
            $stats['account_credit_available_minor'] = $ledger->availableMinor($profile);
            $stats['account_credit_reserved_minor'] = $ledger->reservedMinor($profile);

            $rewardRows = Reward::query()
                ->where('ambassador_profile_id', $profile->id)
                ->get([
                    'id',
                    'status',
                    'amount_minor',
                    'account_credit_bonus_minor_snapshot',
                    'preferred_payout_method_snapshot',
                    'payment_method',
                    'currency',
                ]);

            $pending = 0;
            $approved = 0;
            $paid = 0;
            foreach ($rewardRows as $reward) {
                $display = $reward->memberFacingAmountMinor();
                match ($reward->status) {
                    'pending_approval' => $pending += $display,
                    'approved' => $approved += $display,
                    'paid' => $paid += $display,
                    default => null,
                };
            }

            $stats['pending_reward_minor'] = $pending;
            $stats['approved_reward_minor'] = $approved;
            $stats['paid_reward_minor'] = $paid;
            $stats['lifetime_earned_minor'] =
                $stats['approved_reward_minor'] + $stats['paid_reward_minor'];

            $openClaims = $rewardRows
                ->whereIn('status', ['pending_approval', 'approved'])
                ->sortByDesc('id')
                ->values();
            $stats['open_claims'] = $openClaims;
            $pendingMeta = $this->aggregateClaimHeadline($openClaims->where('status', 'pending_approval'));
            $approvedMeta = $this->aggregateClaimHeadline($openClaims->where('status', 'approved'), approved: true);
            $stats['pending_claim_headline'] = $pendingMeta['headline'];
            $stats['pending_claim_sub'] = $pendingMeta['sub'];
            $stats['approved_claim_headline'] = $approvedMeta['headline'];
            $stats['approved_claim_sub'] = $approvedMeta['sub'];

            // Lifetime stat — never resets on cash-out. This is intentionally
            // distinct from `approved_conversions` (which is active-cycle only).
            $stats['lifetime_approved_referrals'] = ReferralConversion::query()
                ->where('ambassador_profile_id', $profile->id)
                ->where('status', 'approved')
                ->count();

            $progress = $this->milestones->progressFor($profile);
            $stats['approved_conversions'] = $progress->eligibleCount;
            $stats['available_reward_minor'] = $progress->availableAmountMinor;
            $stats['has_available_now'] = $progress->hasClaim();
            $stats['is_max_tier_available'] = $progress->hasClaim() && $progress->nextTier === null;

            if ($progress->nextTier) {
                $stats['progress_current'] = $progress->eligibleCount;
                $stats['progress_target'] = $progress->nextTier->threshold;
                $stats['progress_remaining'] = $progress->referralsRemaining;
                $stats['progress_next_amount_minor'] = $progress->nextTier->total_reward_amount_minor;
                $stats['progress_bonus_amount_minor'] = $progress->nextTier->bonus_amount_minor;
            } elseif ($progress->availableTier) {
                // At (or past) the top tier — no next milestone.
                $stats['progress_current'] = $progress->availableTier->threshold;
                $stats['progress_target'] = $progress->availableTier->threshold;
                $stats['progress_remaining'] = 0;
                $stats['progress_next_amount_minor'] = $progress->availableTier->total_reward_amount_minor;
            }
        }

        $needsPayoutDetails = false;
        if ($profile && ($stats['approved_reward_minor'] ?? 0) > 0) {
            $needsPayoutDetails = ! $profile->hasConfiguredPayoutMethod();
        }

        return view('ambassador.dashboard', [
            'stats' => $stats,
            'needsPayoutDetails' => $needsPayoutDetails,
        ]);
    }

    /**
     * @param Collection<int, Reward> $claims
     * @return array{headline: string, sub: string}
     */
    private function aggregateClaimHeadline($claims, bool $approved = false): array
    {
        if ($claims->isEmpty()) {
            return [
                'headline' => $approved ? 'Approved reward' : 'Pending reward',
                'sub' => $approved ? 'Ready for payout' : 'Awaiting admin approval',
            ];
        }

        $methods = $claims->map(fn (Reward $r) => $r->claimedPayoutMethod()?->value)->unique()->values();
        $single = $claims->count() === 1 ? $claims->first() : null;

        if ($methods->count() === 1 && $methods->first() === 'account_credit') {
            $sub = $approved ? 'Ready to be applied' : 'Awaiting admin approval';
            if ($single && $single->accountCreditBonusMinor() > 0) {
                $sub = $single->amountFormatted().' reward + '.$single->accountCreditBonusFormatted().' bonus · '.$sub;
            }

            return [
                'headline' => $approved ? 'Account Credit ready' : 'Pending Account Credit',
                'sub' => $sub,
            ];
        }

        if ($methods->count() === 1 && $methods->first() === 'bank_transfer') {
            return [
                'headline' => $approved ? 'Bank Transfer ready' : 'Pending Bank Transfer',
                'sub' => $approved ? 'Ready for payout' : 'Awaiting admin approval',
            ];
        }

        // Mixed or legacy (null snapshot) — keep generic cash wording.
        return [
            'headline' => $approved ? 'Approved reward' : 'Pending reward',
            'sub' => $approved ? 'Ready for payout' : 'Awaiting admin approval',
        ];
    }
}
