<?php

namespace App\Models;

use App\Enums\PayoutMethod;
use Database\Factories\MemberPayoutProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payout preference profile per Rewards Member.
 *
 * Sensitive destination fields are encrypted at rest via Eloquent casts.
 * Never log, audit, cache, or put these values into Operations metadata.
 *
 * @property int $id
 * @property int $ambassador_profile_id
 * @property int $user_id
 * @property PayoutMethod $preferred_method
 * @property ?string $account_holder_name
 * @property ?string $sort_code
 * @property ?string $account_number
 * @property ?string $paypal_email
 * @property ?Carbon $verified_at
 */
class MemberPayoutProfile extends Model
{
    /** @use HasFactory<MemberPayoutProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ambassador_profile_id',
        'user_id',
        'preferred_method',
        'account_holder_name',
        'sort_code',
        'account_number',
        'paypal_email',
        'verified_at',
    ];

    /**
     * Keep encrypted destination fields out of array/json serialisation by default.
     *
     * @var list<string>
     */
    protected $hidden = [
        'sort_code',
        'account_number',
        'paypal_email',
        'account_holder_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_method' => PayoutMethod::class,
            'account_holder_name' => 'encrypted',
            'sort_code' => 'encrypted',
            'account_number' => 'encrypted',
            'paypal_email' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AmbassadorProfile, $this> */
    public function ambassadorProfile(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfigured(): bool
    {
        return match ($this->preferred_method) {
            PayoutMethod::BankTransfer => filled($this->account_holder_name)
                && filled($this->sort_code)
                && filled($this->account_number),
            PayoutMethod::PayPal => filled($this->paypal_email),
            PayoutMethod::AccountCredit => true,
            default => false,
        };
    }

    public function maskedSortCode(): ?string
    {
        $raw = $this->normalisedSortCodeDigits();
        if ($raw === null) {
            return null;
        }

        return '**-**-'.substr($raw, -2);
    }

    public function maskedAccountNumber(): ?string
    {
        $raw = $this->account_number;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 4) {
            return '****';
        }

        return '****'.substr($digits, -4);
    }

    public function maskedPayPalEmail(): ?string
    {
        // Legacy helper for historical PayPal rows in masked admin contexts.
        $email = $this->paypal_email;
        if (! is_string($email) || $email === '' || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep).str_repeat('*', max(0, strlen($local) - $keep)).'@'.$domain;
    }

    /**
     * Masked destination summary for normal admin / table views.
     * Never returns full sort code, account number, or PayPal email.
     */
    public function maskedDetailsSummary(): string
    {
        return match ($this->preferred_method) {
            PayoutMethod::BankTransfer => (string) ($this->maskedAccountNumber() ?? '****'),
            PayoutMethod::PayPal => (string) ($this->maskedPayPalEmail() ?? '—'),
            PayoutMethod::AccountCredit => 'Account Credit',
        };
    }

    /**
     * Safe metadata suitable for audit / ops — never includes secrets.
     *
     * @return array{method: string, configured: bool}
     */
    public function auditSafeSnapshot(): array
    {
        return [
            'method' => $this->preferred_method->value,
            'configured' => $this->isConfigured(),
        ];
    }

    private function normalisedSortCodeDigits(): ?string
    {
        $raw = $this->sort_code;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return strlen($digits) === 6 ? $digits : null;
    }
}
