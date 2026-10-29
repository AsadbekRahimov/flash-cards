<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Models\Learner;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §6.1, §8.6: `practice:close-expired` (every 15 minutes) closes the solo sessions past `expires_at` as finished /
 * expired, lets go of the learner's "one active session", and raises `practice.session_finished` once. The request that finds
 * an expired session does the same by one conditional UPDATE: whoever gets there first wins and the other does nothing.
 * `practice:battles-sweep` arrives with 18.6.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function schedulerFinishedEvents(): int
{
    return ProductEvent::query()->where('name', 'practice.session_finished')->count();
}

it('is scheduled every 15 minutes, without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'practice:close-expired'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('closes the sessions past their time as finished / expired, lets the learners go, and leaves the others alone', function (): void {
    $pairs = PracticeSession::factory()->ofMode(PracticeMode::Pairs)->expired()->create(['answered' => 2, 'correct_first' => 1, 'hints' => 1, 'mismatches' => 3, 'total' => 3]);
    $dictation = PracticeSession::factory()->ofMode(PracticeMode::Dictation)->expired()->create(['total' => 5]);
    $fresh = PracticeSession::factory()->create();
    $done = PracticeSession::factory()->expired()->finished(FinishReason::Completed)->create(['finished_at' => '2026-10-29 12:00:00']);
    $edge = PracticeSession::factory()->create(['expires_at' => '2026-10-30 10:00:00']);   // `expires_at <= now` is past
    $nearly = PracticeSession::factory()->create(['expires_at' => '2026-10-30 10:00:01']); // a second to go

    $this->artisan('practice:close-expired')->expectsOutput('Practice sessions closed: 3')->assertSuccessful();

    foreach ([$pairs, $dictation, $edge] as $session) {
        $row = $session->fresh();
        expect($row?->status)->toBe(SessionStatus::Finished)
            ->and($row?->finish_reason)->toBe(FinishReason::Expired)
            ->and($row?->active_learner_id)->toBeNull()
            ->and($row?->finished_at?->toDateTimeString())->toBe('2026-10-30 10:00:00');
    }
    foreach ([$fresh, $nearly] as $session) {
        expect($session->fresh()?->status)->toBe(SessionStatus::Active)
            ->and($session->fresh()?->active_learner_id)->toBe($session->learner_id);
    }
    expect($done->fresh()?->finished_at?->toDateTimeString())->toBe('2026-10-29 12:00:00')
        ->and($done->fresh()?->finish_reason)->toBe(FinishReason::Completed);

    $events = ProductEvent::query()->where('name', 'practice.session_finished')->orderBy('id')->get();
    expect($events)->toHaveCount(3)
        ->and($events->pluck('learner_id')->sort()->values()->all())->toBe(collect([$pairs, $dictation, $edge])->pluck('learner_id')->sort()->values()->all());

    $event = $events->firstWhere('learner_id', $pairs->learner_id);
    expect($event?->props)->toBe([
        'session_id' => $pairs->id, 'mode' => 'pairs', 'total' => 3, 'answered' => 2, 'correct_first' => 1, 'hints' => 1, 'mismatches' => 3,
        'reason' => 'expired', 'duration_ms' => 25 * 3600 * 1000, // started 25 hours ago, closed now
    ]);
});

it('is safe to run again: nothing is closed twice, no second event, and the learner is free to start another session', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);
    $session = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug])->assertCreated()->json();

    CarbonImmutable::setTestNow('2026-10-31 10:00:00');
    $this->artisan('practice:close-expired')->expectsOutput('Practice sessions closed: 1')->assertSuccessful();
    $this->artisan('practice:close-expired')->expectsOutput('Practice sessions closed: 0')->assertSuccessful();

    expect(schedulerFinishedEvents())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($session['id'])->finish_reason)->toBe(FinishReason::Expired);

    // The session is over for the learner: the answer is 410, the summary still reads, no event is added by either.
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'answer' => ['skip' => true],
    ])->assertStatus(410)->assertJsonPath('error.details.finish_reason', 'expired');
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->assertJsonPath('summary.finish_reason', 'expired');
    expect(schedulerFinishedEvents())->toBe(1);

    // ... and a new session opens at once, without `replace_active` and without a 409.
    $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug])->assertCreated();
});

it('leaves a session to the request that closed it first: the run skips it and raises nothing', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);
    $session = $this->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course->slug])->assertCreated()->json();

    CarbonImmutable::setTestNow('2026-10-31 10:00:00');
    $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->assertJsonPath('summary.finish_reason', 'expired');
    expect(schedulerFinishedEvents())->toBe(1);

    $this->artisan('practice:close-expired')->expectsOutput('Practice sessions closed: 0')->assertSuccessful();

    expect(schedulerFinishedEvents())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($session['id'])->finished_at?->toDateTimeString())->toBe('2026-10-31 10:00:00');
});

it('skips a session that was closed between the look and the update, and counts and records only its own', function (): void {
    $lost = PracticeSession::factory()->expired()->create();
    $won = PracticeSession::factory()->expired()->create();

    // The request of the learner gets there between the SELECT of the run and its UPDATE of the first session.
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, $lost): void {
        if (! $armed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from "practice_sessions"')) {
            return;
        }
        $armed = false;
        DB::table('practice_sessions')->where('id', $lost->id)->update([
            'status' => 'finished', 'finish_reason' => 'expired', 'active_learner_id' => null, 'finished_at' => '2026-10-30 09:59:59',
        ]);
    });

    $this->artisan('practice:close-expired')->expectsOutput('Practice sessions closed: 1')->assertSuccessful();

    expect(schedulerFinishedEvents())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->sole()->props['session_id'])->toBe($won->id)
        ->and($lost->fresh()?->finished_at?->toDateTimeString())->toBe('2026-10-30 09:59:59') // the run did not touch it
        ->and($won->fresh()?->finished_at?->toDateTimeString())->toBe('2026-10-30 10:00:00');
});

it('works through any number of sessions in chunks, with the learners loaded once per chunk', function (): void {
    [$course] = PracticeKit::course();
    foreach (Learner::factory()->count(230)->create() as $learner) {
        PracticeSession::factory()->expired()->create(['learner_id' => $learner->id, 'course_id' => $course->id]);
    }

    $queries = QueryLog::during(fn () => Artisan::call('practice:close-expired'));

    expect(Artisan::output())->toContain('Practice sessions closed: 230')
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(0)
        ->and(schedulerFinishedEvents())->toBe(230)
        ->and(count(QueryLog::matching($queries, '/^select .* from ["`]learners["`]/i')))->toBe(2); // 200 + 30
});
