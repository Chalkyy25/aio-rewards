<?php

namespace App\Console\Commands;

use App\Domain\Settings\SettingsRepository;
use App\Enums\OperationsPriority;
use App\Enums\OperationsStatus;
use App\Enums\Role as RoleEnum;
use App\Models\OperationsItem;
use App\Models\User;
use App\Notifications\OperationsDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a digest of the current Operations Centre queue to panel admins.
 * Cadence + on/off toggle both come from Settings.
 */
class SendOperationsRemindersCommand extends Command
{
    protected $signature = 'aio:ops-remind {--force : Ignore the cadence throttle and send immediately}';

    protected $description = 'Send an Operations Centre digest reminder to panel admins.';

    public function handle(SettingsRepository $settings): int
    {
        if ((int) ($settings->value('ops.reminders.enabled') ?? '1') !== 1) {
            $this->info('Reminders disabled in settings — skipping.');

            return self::SUCCESS;
        }

        $cadence = max(1, (int) ($settings->value('ops.reminders.digest_minutes') ?? '60'));
        $cacheKey = 'ops.reminders.last_sent_at';

        if (! $this->option('force')) {
            $lastSent = Cache::get($cacheKey);
            if ($lastSent && now()->diffInMinutes($lastSent) < $cadence) {
                $this->line('Throttled — last sent '.now()->diffInMinutes($lastSent).'m ago; cadence '.$cadence.'m.');

                return self::SUCCESS;
            }
        }

        $base = OperationsItem::query()->whereIn('status', OperationsStatus::openValues());
        $critical = (clone $base)->where('priority', OperationsPriority::Critical->value)->count();
        $high = (clone $base)->where('priority', OperationsPriority::High->value)->count();

        // Only mail when there's something worth mailing about.
        if ($critical === 0 && $high === 0) {
            $this->info('No critical/high items — no digest sent.');
            Cache::put($cacheKey, now(), now()->addDay());

            return self::SUCCESS;
        }

        $summary = [
            'critical' => $critical,
            'high' => $high,
            'overdue' => (clone $base)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'unassigned' => (clone $base)->whereNull('assigned_user_id')->count(),
            'total' => (clone $base)->count(),
            'sample' => (clone $base)
                ->whereIn('priority', [OperationsPriority::Critical->value, OperationsPriority::High->value])
                ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->all(),
        ];

        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [RoleEnum::Admin->value, RoleEnum::SuperAdmin->value]))
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            $this->warn('No panel admins to notify.');

            return self::SUCCESS;
        }

        Notification::send($recipients, new OperationsDigestNotification($summary));
        Cache::put($cacheKey, now(), now()->addDay());

        $this->info('Digest sent to '.$recipients->count().' admin(s).');

        return self::SUCCESS;
    }
}
