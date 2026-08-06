<?php

namespace App\Console\Commands;

use App\Domain\Referrals\ConversionService;
use Illuminate\Console\Command;

class RunReferralApprovalSweepCommand extends Command
{
    protected $signature = 'aio:referrals:approve-ripe {--batch= : Batch size override}';

    protected $description = 'Approve pending ReferralConversions whose approval window has elapsed.';

    public function handle(ConversionService $conversions): int
    {
        $result = $conversions->runApprovalSweep(
            batchSize: $this->option('batch') !== null ? (int) $this->option('batch') : null,
        );

        $this->info(sprintf(
            'Approval sweep complete — scanned: %d, approved: %d, skipped: %d',
            $result['scanned'],
            $result['approved'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
