<?php

namespace App\Domain\Rewards;

use RuntimeException;

class RewardFundingIntegrityException extends RuntimeException
{
    public static function invalid(string $reason): self
    {
        return new self('Reward funding integrity check failed: '.$reason);
    }
}
