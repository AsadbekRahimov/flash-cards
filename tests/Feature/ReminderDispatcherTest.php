<?php

declare(strict_types=1);

use App\Domain\Learners\Services\ReminderDispatcher;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

/*
 * ReminderDispatcher: the claim of `learners.next_reminder_at` (docs/12 §7.5, docs/06 §6).
 * Runs on sqlite and PostgreSQL alike; the real multi-process race is in
 * ReminderDispatcherConcurrencyTest (pgsql only).
 */

uses(RefreshDatabase::class);

function rdUtc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

/** @param array<string, mixed> $attributes */
function rdLearner(string $nextReminderAt, array $attributes = []): Learner
{
    return Learner::factory()->create([
        'timezone' => 'Asia/Tashkent',   // UTC+5, no DST: 19:00 local = 14:00 UTC
        'reminder_time' => '19:00',
        'next_reminder_at' => rdUtc($nextReminderAt),
        ...$attributes,
    ]);
}

function rdPointer(Learner $learner): ?string
{
    return $learner->fresh()?->next_reminder_at?->utc()->toDateTimeString();
}

beforeEach(function (): void {
    Bus::fake();
});

it('claims every learner whose reminder time has come, queues one job each and moves the pointer to the next local occurrence', function (): void {
    $now = rdUtc('2026-10-18 14:05:00');
    $due = rdLearner('2026-10-18 14:00:00');
    $exactlyNow = rdLearner('2026-10-18 14:05:00');
    $later = rdLearner('2026-10-18 14:06:00');
    $tomorrow = rdLearner('2026-10-19 14:00:00');

    $report = app(ReminderDispatcher::class)->dispatchDue($now);

    expect($report->claimed)->toBe(2)
        ->and($report->queued)->toBe(2)
        ->and($report->skippedStale)->toBe(0)
        ->and($report->backfilled)->toBe(0)
        ->and(rdPointer($due))->toBe('2026-10-19 14:00:00')
        ->and(rdPointer($exactlyNow))->toBe('2026-10-19 14:00:00')
        ->and(rdPointer($later))->toBe('2026-10-18 14:06:00')
        ->and(rdPointer($tomorrow))->toBe('2026-10-19 14:00:00');

    Bus::assertDispatchedTimes(SendLearnerReminderJob::class, 2);
    Bus::assertDispatched(SendLearnerReminderJob::class, fn (SendLearnerReminderJob $job): bool => $job->learnerId === $due->id
        && $job->scheduledFor->utc()->toDateTimeString() === '2026-10-18 14:00:00'
        && $job->queue === 'default');
    Bus::assertDispatched(SendLearnerReminderJob::class, fn (SendLearnerReminderJob $job): bool => $job->learnerId === $exactlyNow->id);
});

it('spreads the jobs so that no more than send_rate start per second, never later than 14 minutes', function (): void {
    config(['learning.reminders.send_rate_per_second' => 2]);
    foreach (range(1, 5) as $i) {
        rdLearner('2026-10-18 14:00:00');
    }

    app(ReminderDispatcher::class)->dispatchDue(rdUtc('2026-10-18 14:05:00'));

    $delays = [];
    Bus::assertDispatched(SendLearnerReminderJob::class, function (SendLearnerReminderJob $job) use (&$delays): bool {
        $delays[] = $job->delay;

        return true;
    });
    sort($delays);
    expect($delays)->toBe([0, 0, 1, 1, 2]);

    $dispatcher = app(ReminderDispatcher::class);
    expect($dispatcher->delaySeconds(0))->toBe(0)
        ->and($dispatcher->delaySeconds(3))->toBe(1)
        ->and($dispatcher->delaySeconds(1_000))->toBe(500)
        ->and($dispatcher->delaySeconds(1_000_000))->toBe(840);
});

it('defaults to 25 sends per second and treats a nonsensical rate as 1', function (): void {
    expect(config('learning.reminders.send_rate_per_second'))->toBe(25)
        ->and(app(ReminderDispatcher::class)->delaySeconds(100))->toBe(4);

    config(['learning.reminders.send_rate_per_second' => 0]);
    expect(app(ReminderDispatcher::class)->delaySeconds(100))->toBe(100);
});

it('does not queue anyone twice when the dispatcher runs again for the same minute', function (): void {
    $now = rdUtc('2026-10-18 14:05:00');
    foreach (range(1, 5) as $i) {
        rdLearner('2026-10-18 14:00:00');
    }

    $first = app(ReminderDispatcher::class)->dispatchDue($now);
    $second = app(ReminderDispatcher::class)->dispatchDue($now);
    $third = app(ReminderDispatcher::class)->dispatchDue($now->addMinutes(15));

    expect($first->claimed)->toBe(5)
        ->and($second->claimed)->toBe(0)
        ->and($third->claimed)->toBe(0);
    Bus::assertDispatchedTimes(SendLearnerReminderJob::class, 5);
});

it('skips a learner whose pointer another instance moved between the SELECT and the UPDATE', function (): void {
    $now = rdUtc('2026-10-18 14:05:00');
    $learner = rdLearner('2026-10-18 14:00:00');

    // The loser of a race: right after this instance read the row, another one claims it.
    $raced = false;
    Learner::retrieved(function (Learner $retrieved) use ($learner, &$raced): void {
        if ($retrieved->id === $learner->id && ! $raced) {
            $raced = true;
            Learner::query()->whereKey($learner->id)->update(['next_reminder_at' => rdUtc('2026-10-19 14:00:00')]);
        }
    });

    $report = app(ReminderDispatcher::class)->dispatchDue($now);

    expect($raced)->toBeTrue()
        ->and($report->claimed)->toBe(0)
        ->and(rdPointer($learner))->toBe('2026-10-19 14:00:00');
    Bus::assertNothingDispatched();
});

it('leaves out learners with a blocked DM, a platform ban, a deleted account, no pointer or reminders off', function (): void {
    $now = rdUtc('2026-10-18 14:05:00');
    $dmBlocked = rdLearner('2026-10-18 14:00:00', ['dm_blocked_at' => rdUtc('2026-10-01 00:00:00')]);
    $banned = rdLearner('2026-10-18 14:00:00', ['blocked_at' => rdUtc('2026-10-01 00:00:00')]);
    $deleted = rdLearner('2026-10-18 14:00:00');
    $deleted->delete();
    $off = Learner::factory()->create(['reminder_time' => null, 'next_reminder_at' => null]);
    $reachable = rdLearner('2026-10-18 14:00:00');

    $report = app(ReminderDispatcher::class)->dispatchDue($now);

    expect($report->claimed)->toBe(1)
        ->and(rdPointer($dmBlocked))->toBe('2026-10-18 14:00:00')
        ->and(rdPointer($banned))->toBe('2026-10-18 14:00:00')
        ->and(Learner::withTrashed()->find($deleted->id)?->next_reminder_at?->utc()->toDateTimeString())->toBe('2026-10-18 14:00:00')
        ->and(rdPointer($off))->toBeNull()
        ->and(rdPointer($reachable))->toBe('2026-10-19 14:00:00');
    Bus::assertDispatchedTimes(SendLearnerReminderJob::class, 1);
    Bus::assertDispatched(SendLearnerReminderJob::class, fn (SendLearnerReminderJob $job): bool => $job->learnerId === $reachable->id);
});

it('repairs a leftover pointer of a learner who switched reminders off instead of queueing a job', function (): void {
    $now = rdUtc('2026-10-18 14:05:00');
    $leftover = rdLearner('2026-10-18 14:00:00', ['reminder_time' => null]);

    $report = app(ReminderDispatcher::class)->dispatchDue($now);

    expect($report->claimed)->toBe(0)
        ->and($report->repaired)->toBe(1)
        ->and(rdPointer($leftover))->toBeNull();
    Bus::assertNothingDispatched();
});

it('reminds a learner who has not finished the onboarding — one attempt, Telegram decides', function (): void {
    $now = rdUtc('2026-10-18 14:05:00');
    $fresh = rdLearner('2026-10-18 14:00:00', ['onboarding_state' => 'new']);
    $midway = rdLearner('2026-10-18 14:00:00', ['onboarding_state' => 'goal']);

    expect(app(ReminderDispatcher::class)->dispatchDue($now)->queued)->toBe(2);
    Bus::assertDispatched(SendLearnerReminderJob::class, fn (SendLearnerReminderJob $job): bool => $job->learnerId === $fresh->id);
    Bus::assertDispatched(SendLearnerReminderJob::class, fn (SendLearnerReminderJob $job): bool => $job->learnerId === $midway->id);
});

it('catches up after downtime in one run without sending the stale reminders', function (): void {
    config(['learning.reminders.max_delay_minutes' => 120]);
    $now = rdUtc('2026-10-21 09:00:00');
    $threeDaysLate = rdLearner('2026-10-18 14:00:00');
    $justInTime = rdLearner('2026-10-21 07:30:00', ['timezone' => 'Europe/Moscow', 'reminder_time' => '10:30']);
    $tooLate = rdLearner('2026-10-21 06:59:00', ['timezone' => 'Europe/Moscow', 'reminder_time' => '09:59']);

    $report = app(ReminderDispatcher::class)->dispatchDue($now);

    expect($report->claimed)->toBe(3)
        ->and($report->queued)->toBe(1)
        ->and($report->skippedStale)->toBe(2)
        // next occurrence is computed from NOW, not from the stale value: today 19:00 Tashkent, not 2026-10-19.
        ->and(rdPointer($threeDaysLate))->toBe('2026-10-21 14:00:00')
        ->and(rdPointer($justInTime))->toBe('2026-10-22 07:30:00')
        ->and(rdPointer($tooLate))->toBe('2026-10-22 06:59:00');
    Bus::assertDispatchedTimes(SendLearnerReminderJob::class, 1);
    Bus::assertDispatched(SendLearnerReminderJob::class, fn (SendLearnerReminderJob $job): bool => $job->learnerId === $justInTime->id);
});

it('moves the pointer across a DST change by wall-clock time (Europe/Berlin)', function (): void {
    // Saturday 2026-03-28 19:00 CET = 18:00 UTC. Sunday 29th is CEST: 19:00 = 17:00 UTC.
    $berlin = rdLearner('2026-03-28 18:00:00', ['timezone' => 'Europe/Berlin', 'reminder_time' => '19:00']);
    // Fall-back: Saturday 2026-10-24 19:00 CEST = 17:00 UTC; Sunday 25th is CET again: 19:00 = 18:00 UTC.
    $autumn = rdLearner('2026-10-24 17:00:00', ['timezone' => 'Europe/Berlin', 'reminder_time' => '19:00']);
    // A reminder inside the spring-forward gap (02:30 does not exist on the 29th) lands on 03:30 CEST = 01:30 UTC.
    $gap = rdLearner('2026-03-28 01:30:00', ['timezone' => 'Europe/Berlin', 'reminder_time' => '02:30']);

    app(ReminderDispatcher::class)->dispatchDue(rdUtc('2026-03-28 18:01:00'));

    expect(rdPointer($berlin))->toBe('2026-03-29 17:00:00')
        ->and($berlin->fresh()?->next_reminder_at?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-03-29 19:00')
        ->and(rdPointer($gap))->toBe('2026-03-29 01:30:00')
        ->and($gap->fresh()?->next_reminder_at?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-03-29 03:30')
        ->and(rdPointer($autumn))->toBe('2026-10-24 17:00:00');

    app(ReminderDispatcher::class)->dispatchDue(rdUtc('2026-10-24 17:01:00'));

    expect(rdPointer($autumn))->toBe('2026-10-25 18:00:00')
        ->and($autumn->fresh()?->next_reminder_at?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-25 19:00');
});

// --- backfill --------------------------------------------------------------------

it('backfills next_reminder_at for learners who have a reminder time but no pointer, skipping unreachable ones', function (): void {
    $now = rdUtc('2026-10-18 10:00:00'); // 15:00 Tashkent -> today 19:00 local = 14:00 UTC
    $missing = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'reminder_time' => '19:00', 'next_reminder_at' => null]);
    $afterToday = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'reminder_time' => '08:00', 'next_reminder_at' => null]);
    $off = Learner::factory()->create(['reminder_time' => null, 'next_reminder_at' => null]);
    $dmBlocked = Learner::factory()->create(['reminder_time' => '19:00', 'next_reminder_at' => null, 'dm_blocked_at' => $now]);
    $banned = Learner::factory()->create(['reminder_time' => '19:00', 'next_reminder_at' => null, 'blocked_at' => $now]);
    $deleted = Learner::factory()->create(['reminder_time' => '19:00', 'next_reminder_at' => null]);
    $deleted->delete();
    $already = rdLearner('2026-10-18 14:00:00');

    $report = app(ReminderDispatcher::class)->dispatchDue($now);

    expect($report->backfilled)->toBe(2)
        ->and($report->claimed)->toBe(0) // the backfilled pointers are strictly after now: not claimed in the same run
        ->and(rdPointer($missing))->toBe('2026-10-18 14:00:00')
        ->and(rdPointer($afterToday))->toBe('2026-10-19 03:00:00')
        ->and(rdPointer($off))->toBeNull()
        ->and(rdPointer($dmBlocked))->toBeNull()
        ->and(rdPointer($banned))->toBeNull()
        ->and(Learner::withTrashed()->find($deleted->id)?->next_reminder_at)->toBeNull()
        ->and(rdPointer($already))->toBe('2026-10-18 14:00:00');
    Bus::assertNothingDispatched();

    // Idempotent: nothing left to fill.
    expect(app(ReminderDispatcher::class)->backfillMissing($now))->toBe(0);
});

it('backfills up to 10000 learners per run by default', function (): void {
    expect(config('learning.reminders.backfill_limit'))->toBe(10_000);
});

it('caps the backfill per run and continues on the next one', function (): void {
    config(['learning.reminders.backfill_limit' => 2]);
    Learner::factory()->count(5)->create(['reminder_time' => '19:00', 'next_reminder_at' => null]);

    expect(app(ReminderDispatcher::class)->dispatchDue(rdUtc('2026-10-18 10:00:00'))->backfilled)->toBe(2)
        ->and(app(ReminderDispatcher::class)->dispatchDue(rdUtc('2026-10-18 10:00:00'))->backfilled)->toBe(2)
        ->and(app(ReminderDispatcher::class)->dispatchDue(rdUtc('2026-10-18 10:00:00'))->backfilled)->toBe(1)
        ->and(Learner::query()->whereNull('next_reminder_at')->count())->toBe(0);
});

it('does not double-fill a pointer that a settings change set between the SELECT and the UPDATE', function (): void {
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'reminder_time' => '19:00', 'next_reminder_at' => null]);

    // The learner picks 08:00 right after the dispatcher read the row with a NULL pointer.
    Learner::query()->whereKey($learner->id)->update(['reminder_time' => '08:00', 'next_reminder_at' => rdUtc('2026-10-19 03:00:00')]);
    $filled = app(ReminderDispatcher::class)->backfillMissing(rdUtc('2026-10-18 10:00:00'));

    expect($filled)->toBe(0)
        ->and(rdPointer($learner))->toBe('2026-10-19 03:00:00');
});

// --- command and schedule -----------------------------------------------------------

it('runs through the artisan command and reports the counters', function (): void {
    $this->travelTo(rdUtc('2026-10-18 14:05:00'));
    rdLearner('2026-10-18 14:00:00');
    Learner::factory()->create(['reminder_time' => '19:00', 'next_reminder_at' => null]);

    $this->artisan('reminders:dispatch')
        ->expectsOutput('Reminders: claimed 1, queued 1, skipped stale 0, repaired 0, backfilled 1.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SendLearnerReminderJob::class, 1);
});

it('is scheduled every fifteen minutes without overlapping, and the daily repetitions:notify is gone', function (): void {
    $events = collect(Schedule::events());

    $dispatch = $events->first(fn ($e): bool => str_contains((string) $e->command, 'reminders:dispatch'));
    expect($dispatch)->not->toBeNull()
        ->and($dispatch->expression)->toBe('*/15 * * * *')
        ->and($dispatch->withoutOverlapping)->toBeTrue()
        // The mutex expires after 10 minutes: a crashed run cannot block the schedule for the default 24 h.
        ->and($dispatch->expiresAt)->toBe(10)
        ->and($dispatch->runInBackground)->toBeTrue();

    expect($events->first(fn ($e): bool => str_contains((string) $e->command, 'repetitions:notify')))->toBeNull();
});
