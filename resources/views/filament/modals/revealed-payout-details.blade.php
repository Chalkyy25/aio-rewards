@php
    /** @var \App\Domain\Payouts\RevealedPayoutDetails|null $details */
@endphp

<div data-testid="revealed-payout-details-modal" class="space-y-4" x-data>
    <div class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-900 dark:border-warning-600 dark:bg-warning-950 dark:text-warning-100">
        Sensitive payout information. Only use these details to process the requested reward payment.
    </div>

    @if (! $details)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Payout details are no longer available. Close this dialog and run Reveal payout details again if needed.
        </p>
    @elseif ($details->hasBankTransferDetails())
        <dl class="space-y-4">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Account holder</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-base text-gray-950 dark:text-white" data-testid="revealed-account-holder">{{ $details->accountHolderName ?: '—' }}</span>
                    @if (filled($details->accountHolderName))
                        <x-filament::button
                            color="gray"
                            size="xs"
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($details->accountHolderName))"
                        >
                            Copy
                        </x-filament::button>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Sort code</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-base text-gray-950 dark:text-white" data-testid="revealed-sort-code">{{ $details->sortCode ?: '—' }}</span>
                    @if (filled($details->sortCode))
                        <x-filament::button
                            color="gray"
                            size="xs"
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($details->sortCode))"
                        >
                            Copy
                        </x-filament::button>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Account number</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-base text-gray-950 dark:text-white" data-testid="revealed-account-number">{{ $details->accountNumber ?: '—' }}</span>
                    @if (filled($details->accountNumber))
                        <x-filament::button
                            color="gray"
                            size="xs"
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($details->accountNumber))"
                        >
                            Copy
                        </x-filament::button>
                    @endif
                </dd>
            </div>
        </dl>
    @elseif ($details->method === \App\Enums\PayoutMethod::PayPal)
        <dl class="space-y-4">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">PayPal email (legacy)</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-base text-gray-950 dark:text-white" data-testid="revealed-paypal-email">{{ $details->paypalEmail ?: '—' }}</span>
                    @if (filled($details->paypalEmail))
                        <x-filament::button
                            color="gray"
                            size="xs"
                            type="button"
                            x-on:click="navigator.clipboard.writeText(@js($details->paypalEmail))"
                        >
                            Copy
                        </x-filament::button>
                    @endif
                </dd>
            </div>
        </dl>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">No sensitive destination is stored for this payout preference.</p>
    @endif
</div>
