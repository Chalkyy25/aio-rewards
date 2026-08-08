{{-- Expects $tier: RewardMilestoneTier --}}
<div data-testid="reward-payout-choice" style="margin:1.25rem 0;display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem;color:#0f172a">
        <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Bank Transfer</div>
        <div style="font-size:1.35rem;font-weight:700;margin:.35rem 0" data-testid="payout-bank-amount">
            £{{ number_format($tier->total_reward_amount_minor / 100, 0) }} CASH
        </div>
        <p style="margin:0;font-size:.9rem;color:#475569">Receive £{{ number_format($tier->total_reward_amount_minor / 100, 0) }} directly to your bank.</p>
    </div>
    <div style="background:#0f172a;border-radius:.75rem;padding:1rem;color:#fff">
        <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;opacity:.75">Account Credit</div>
        <div style="font-size:1.35rem;font-weight:700;margin:.35rem 0" data-testid="payout-credit-amount">
            £{{ number_format($tier->accountCreditTotalMinor() / 100, 0) }} CREDIT
        </div>
        @if ($tier->account_credit_bonus_minor > 0)
            <div style="font-size:.9rem;color:#86efac;margin-bottom:.35rem" data-testid="payout-credit-bonus">
                +£{{ number_format($tier->account_credit_bonus_minor / 100, 0) }} BONUS
            </div>
        @endif
        <p style="margin:0;font-size:.9rem;opacity:.85">
            @if ($tier->account_credit_bonus_minor > 0)
                Get an extra £{{ number_format($tier->account_credit_bonus_minor / 100, 0) }} by keeping your reward as AIO Account Credit.
            @else
                Keep your reward as AIO Account Credit toward package purchases.
            @endif
        </p>
    </div>
</div>
