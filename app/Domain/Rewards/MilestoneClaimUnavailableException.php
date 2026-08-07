<?php

namespace App\Domain\Rewards;

/**
 * Thrown when a claim can no longer be honoured because the underlying
 * progression state has shifted (e.g. concurrent claim, refunds).
 */
class MilestoneClaimUnavailableException extends \RuntimeException
{
}
