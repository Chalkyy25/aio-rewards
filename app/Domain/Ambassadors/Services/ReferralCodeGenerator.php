<?php

namespace App\Domain\Ambassadors\Services;

use App\Models\AmbassadorProfile;
use Illuminate\Support\Str;

/**
 * Generates unique, human-readable referral codes.
 *
 * 8-char Crockford-friendly base32 (no O/I/1/0 collisions with lookalike glyphs).
 * Collision-checked on insert; the caller must be inside a transaction if it
 * cares about race safety (the DB unique index is the ultimate guarantor).
 */
class ReferralCodeGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(private readonly int $length = 8) {}

    public function unique(): string
    {
        // 8-char alphabet ^32 = ~10^12 space; ~10 attempts is beyond astronomical.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->randomCode();
            if (! AmbassadorProfile::where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        // Vanishingly rare — fall back to a longer code rather than fail.
        return $this->randomCode($this->length + 4);
    }

    private function randomCode(?int $length = null): string
    {
        $length ??= $this->length;
        $alpha = self::ALPHABET;
        $max = strlen($alpha) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alpha[random_int(0, $max)];
        }

        return $out;
    }

    public function forceUniqueForTests(): string
    {
        return Str::upper(Str::random($this->length));
    }
}
