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

// Operations Centre scanner — detects new business events every minute,
// dedupes against open items, auto-resolves cleared conditions, and
// runs the escalation sweep in the same pass.
Schedule::command('aio:ops-scan')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('ops.scan');

// Reminder digest — emails a summary of open critical/high items to
// panel admins on the configured interval. No-ops when disabled.
Schedule::command('aio:ops-remind')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('ops.reminders');
