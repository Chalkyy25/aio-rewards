<?php

namespace App\Domain\Referrals\Support;

/**
 * Lightweight bot detector — substring match against the user agent.
 * Not a fraud engine; missing entries are cheap to add via config.
 */
class BotFilter
{
    /**
     * @param array<int, string>|null $substrings
     */
    public function __construct(private ?array $substrings = null)
    {
        $this->substrings = $substrings ?? config('referrals.bot_ua_substrings', []);
    }

    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return true;
        }

        $ua = strtolower($userAgent);
        foreach ($this->substrings ?? [] as $needle) {
            if ($needle !== '' && str_contains($ua, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
