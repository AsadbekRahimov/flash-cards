<?php

declare(strict_types=1);

use App\Domain\Operations\Services\SchedulerHeartbeat;
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

// Time-based subscription transitions (period end -> past_due / expired),
// docs/12 §5.6. Idempotent: every row is re-checked under lockForUpdate.
Schedule::command('subscriptions:expire')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('repetitions:notify')
    ->dailyAt('18:00')
    ->withoutOverlapping();

// Retention of product_events (operations.product_events.retention_days).
Schedule::command('model:prune', ['--model' => [ProductEvent::class]])->daily()->at('03:00');

// Daily DB + storage backup via spatie/laravel-backup.
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('backup:monitor')->daily()->at('02:00');
