<?php

use App\Jobs\ApproveRipeReferralConversionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatic sweeper — runs hourly, catches any pending conversion whose
// approval window has just elapsed. Job is ShouldBeUnique so overlapping
// scheduler + manual admin triggers stay safe.
Schedule::job(new ApproveRipeReferralConversionsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('referrals.approval-sweep');
