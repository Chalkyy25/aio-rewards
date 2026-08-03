<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * Diagnostic job used by the Phase 0 Horizon health probe.
 * Writes a stamped Redis key so the operator can confirm end-to-end
 * dispatch → Horizon → worker processing. Not part of the production
 * runtime; safe to leave in the codebase for future re-verification.
 */
class HorizonHealthProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private string $redisKey) {}

    public function handle(): void
    {
        Redis::setex($this->redisKey, 60, 'processed@'.now()->toIso8601String());
    }
}
