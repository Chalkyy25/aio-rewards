<?php

namespace App\Listeners;

use App\Enums\Role as RoleEnum;
use App\Models\AmbassadorProfile;
use App\Models\User;
use App\Notifications\AmbassadorWelcomeNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When Laravel's `Verified` event fires, send the AmbassadorWelcomeNotification
 * ONCE — but only if:
 *   - the user is an Ambassador; and
 *   - `welcome_email_sent_at` is currently NULL.
 *
 * Race safety is enforced by a single-row `UPDATE ... WHERE welcome_email_sent_at IS NULL`
 * inside a transaction; if the affected-rows count is 0 we skip silently. This
 * makes duplicate Verified events, replayed jobs, or two simultaneous verify
 * requests idempotent by design.
 */
class SendAmbassadorWelcomeAfterVerified implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(Verified $event): void
    {
        /** @var User $user */
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }
        if (! $user->hasRole(RoleEnum::Ambassador->value)) {
            return;
        }

        // Atomic claim of the "already sent" marker. If another worker /
        // request / replayed job has already claimed it, affected = 0.
        $sentAt = now();
        $affected = DB::transaction(function () use ($user, $sentAt): int {
            return User::query()
                ->whereKey($user->getKey())
                ->whereNull('welcome_email_sent_at')
                ->update(['welcome_email_sent_at' => $sentAt]);
        });

        if ($affected === 0) {
            Log::info('ambassador.welcome_email.skipped_duplicate', [
                'user_id' => $user->getKey(),
            ]);

            return;
        }

        $ambassador = AmbassadorProfile::where('user_id', $user->getKey())->first();
        if ($ambassador === null) {
            // Verified user has ambassador role but no profile — extremely
            // unlikely in the wild but we release the marker so a valid
            // ambassador can retry later.
            User::query()->whereKey($user->getKey())->update(['welcome_email_sent_at' => null]);
            Log::warning('ambassador.welcome_email.missing_profile', [
                'user_id' => $user->getKey(),
            ]);

            return;
        }

        try {
            $user->notify(new AmbassadorWelcomeNotification($ambassador));
        } catch (\Throwable $e) {
            // Release the marker so a follow-up event can retry.
            User::query()->whereKey($user->getKey())->update(['welcome_email_sent_at' => null]);
            Log::error('ambassador.welcome_email.dispatch_failed', [
                'user_id' => $user->getKey(),
                'exception' => $e::class,
                // Do NOT log the message body / verification URL / any tokens.
            ]);
            throw $e;
        }

        AuditLogger::record(
            action: 'ambassador.welcome_email.sent',
            subject: $ambassador,
            after: ['user_id' => $user->getKey(), 'sent_at' => $sentAt->toIso8601String()],
            actor: $user,
        );
    }
}
