<?php

namespace App\Jobs;

use App\Domain\Referrals\ConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the automatic ReferralConversion approval sweep. Marked as
 * `ShouldBeUnique` so overlapping scheduler + manual runs do not queue
 * multiple parallel copies. Each candidate row is additionally row-locked
 * inside ConversionService::runApprovalSweep.
 */
class ApproveRipeReferralConversionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 600; // seconds

    public function __construct(public readonly ?int $batchSize = null) {}

    public function uniqueId(): string
    {
        return 'referral-conversion-approval-sweep';
    }

    public function handle(ConversionService $conversions): array
    {
        return $conversions->runApprovalSweep(batchSize: $this->batchSize);
    }
}
