<?php

declare(strict_types=1);

use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Liveness marker for GET /health/ready: stale heartbeat (> 3 min) = scheduler is down.
Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())
    ->name('ops:scheduler-heartbeat')
    ->everyMinute();

// Close exams whose `ends_at` passed more than a minute ago and post the
// group leaderboard. See CloseExpiredExams for idempotency guarantees.
Schedule::command('exams:close-expired')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Personal sessions past `expires_at` and stale group sessions get `done_reason = expired`
// (docs/12 §7.6). A plain UPDATE, so overlapping or repeated runs are harmless.
Schedule::command('training:close-expired')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Friend challenges (docs/13 §7.5): active ones past `ends_at` are scored from learner_daily_stats and finished,
// unanswered pending ones expire. Idempotent: the claim is a conditional UPDATE, so a repeated run raises nothing twice.
Schedule::command('challenges:close-due')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Time-based subscription transitions (period end -> past_due / expired),
// docs/12 §5.6. Idempotent: every row is re-checked under lockForUpdate.
Schedule::command('subscriptions:expire')
    ->hourly()
    ->withoutOverlapping();

// Pending payment orders past their TTL become `expired` (docs/13 §4.4): a plain UPDATE without
// subject locks, so overlapping or repeated runs are harmless.
Schedule::command('billing:expire-orders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Renewal reminders (docs/13 §4.4): 3 and 1 day before a non-renewing period ends. 06:00 UTC = 11:00
// in Tashkent. Idempotent: the markers live in `subscriptions.meta.renewal_reminders`, so a re-run
// (or a second scheduler) raises nothing twice.
Schedule::command('subscriptions:remind-expiring')
    ->dailyAt('06:00')
    ->withoutOverlapping();

// Repair path of the group -> organization membership hook (docs/13 §2.3): any learner in a
// group of an organization without a membership row gets one (joined_via = group). Idempotent.
Schedule::command('organizations:sync-members')
    ->hourly()
    ->withoutOverlapping();

// Assignment snapshots + group reminders 24 h before the deadline (docs/13 §1.4). Idempotent:
// the one-time posts are claimed by conditional UPDATEs (`announced_at` / `reminded_at`).
Schedule::command('assignments:refresh')
    ->everyThirtyMinutes()
    ->withoutOverlapping(20);

// League weeks (docs/13 §7.3): Monday 00:05 in the league time zone closes the week that just
// ended (final standings, promotions, result DMs through the global send budget). Runs daily so
// a missed Monday is caught up the next night; idempotent by `league_weeks.status`.
Schedule::command('leagues:close-week')
    ->dailyAt('00:05')
    ->timezone((string) config('leagues.timezone', 'Asia/Tashkent'))
    ->withoutOverlapping(30);

// Referral rewards (docs/13 §7.1): re-queues the reward job of a referral that is `activated`, or
// `rewarded` with a side still missing, after a lost / failed job. Idempotent by the data.
Schedule::command('referrals:recover')
    ->everyThirtyMinutes()
    ->withoutOverlapping(20);

// Telegram's Stars ledger vs ours for the last 2 days (docs/13 §4.4): read-only, logs
// `billing.reconcile_missing*`; exits early while Stars is disabled.
Schedule::command('billing:reconcile-stars')
    ->dailyAt('04:30')
    ->withoutOverlapping();

// Personal reminders by the learner's local time (docs/12 §7.5): claims
// `learners.next_reminder_at <= now` with a conditional UPDATE, so a parallel or
// repeated run never reminds anyone twice.
Schedule::command('reminders:dispatch')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// Retention of product_events and notifications_log (operations.*.retention_days).
Schedule::command('model:prune', ['--model' => [ProductEvent::class, NotificationLog::class]])->daily()->at('03:00');

// Daily DB + storage backup via spatie/laravel-backup.
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('backup:monitor')->daily()->at('02:00');
