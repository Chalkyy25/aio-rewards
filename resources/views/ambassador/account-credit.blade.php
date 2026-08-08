@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AccountCreditTransaction> $transactions */
    /** @var \Illuminate\Support\Collection<int, \App\Models\AccountCreditReservation> $reservations */
@endphp
<div data-testid="account-credit-page" style="max-width:860px;margin:0 auto">
    <h1 style="font-size:1.5rem;margin:0 0 .5rem">Account Credit</h1>
    <p style="color:#64748b;margin:0 0 1.5rem">
        Your AIO Account Credit balance. Use available credit toward eligible AIO Media package purchases at checkout.
    </p>

    <div data-testid="account-credit-balance"
         style="background:#0f172a;color:#fff;border-radius:.75rem;padding:1.5rem;margin-bottom:1.25rem">
        <div style="opacity:.8;font-size:.9rem;margin-bottom:.35rem">Account Credit Balance</div>
        <div style="font-size:2rem;font-weight:700;letter-spacing:-.02em" data-testid="account-credit-balance-total">{{ $balanceFormatted }}</div>
        <div style="display:flex;gap:1.5rem;margin-top:1rem;flex-wrap:wrap;font-size:.95rem">
            <div data-testid="account-credit-available">
                <div style="opacity:.7;font-size:.8rem">Available</div>
                <div style="font-weight:600">{{ $availableFormatted }}</div>
            </div>
            @if ($reservedMinor > 0)
                <div data-testid="account-credit-reserved">
                    <div style="opacity:.7;font-size:.8rem">Reserved</div>
                    <div style="font-weight:600">{{ $reservedFormatted }}</div>
                </div>
            @endif
        </div>
    </div>

    <p style="margin:0 0 1.25rem">
        <a href="{{ route('packages') }}" data-testid="account-credit-shop-cta"
           style="display:inline-block;padding:.75rem 1.1rem;background:#0f172a;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600">
            Browse packages
        </a>
    </p>

    <h2 style="font-size:1.1rem;margin:0 0 .75rem">Transaction history</h2>
    @if ($transactions->isEmpty())
        <p data-testid="account-credit-empty" style="color:#64748b">No Account Credit transactions yet.</p>
    @else
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden">
            <table data-testid="account-credit-history" style="width:100%;border-collapse:collapse;font-size:.95rem">
                <thead>
                <tr style="background:#f8fafc;text-align:left">
                    <th style="padding:.75rem 1rem;border-bottom:1px solid #e2e8f0">When</th>
                    <th style="padding:.75rem 1rem;border-bottom:1px solid #e2e8f0">Type</th>
                    <th style="padding:.75rem 1rem;border-bottom:1px solid #e2e8f0">Amount</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($transactions as $tx)
                    <tr>
                        <td style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9">{{ $tx->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        <td style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9">{{ $tx->sourceLabel() }}</td>
                        <td style="padding:.75rem 1rem;border-bottom:1px solid #f1f5f9;font-weight:600;color:{{ $tx->amount_minor >= 0 ? '#047857' : '#b91c1c' }}">
                            {{ $tx->amountFormatted() }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
