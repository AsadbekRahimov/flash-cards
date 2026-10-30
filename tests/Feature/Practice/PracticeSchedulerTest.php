<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Practice\BattleKit;
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
    // `toEqual`: jsonb does not keep the order of the keys.
    expect($event?->props)->toEqual([
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

// ------------------------------------------------------------------------------------------------------------ practice:battles-sweep (18.6)

it('sweeps the battles every minute, without overlapping, in the background', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'practice:battles-sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('expires the invitations past their time, moves the matches that are on by the clock, leaves the rest alone, and does nothing twice', function (): void {
    Storage::fake('practice_audio');
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $pair = fn (): array => [$a = Learner::factory()->create(), $b = Learner::factory()->create(['interface_locale' => 'uz']), Friendship::factory()->between($a, $b)->create()];
    [$a1, $b1, $f1] = $pair();
    [$a2, $b2, $f2] = $pair();
    [$a3, $b3, $f3] = $pair();
    [$a4, $b4, $f4] = $pair();

    $expired = BattleKit::invite($a1, $f1, PracticeMode::Pairs, $course);             // past its 5 minutes
    $fresh = BattleKit::invite($a2, $f2, PracticeMode::Pairs, $course);               // 10:05:00 is its last second
    $countdown = BattleKit::countdown($a3, $b3, $f3, PracticeMode::Collocation, $course); // starts at 10:00:03
    $game = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course); // round 1 until 10:00:33
    BattleKit::answer($game, $me, 1, BattleKit::correct(BattleKit::element($game, 1)));
    $done = BattleKit::countdown($a4, $b4, $f4, PracticeMode::Sentence, $course);
    app(BattleService::class)->surrender($a4, $done->id);
    Battle::query()->whereKey($expired->id)->update(['invite_expires_at' => '2026-10-30 10:04:59']);
    Battle::query()->whereKey($fresh->id)->update(['invite_expires_at' => '2026-10-30 10:05:00']);
    Battle::query()->whereKey($game->id)->update(['round_deadline_at' => '2026-10-30 10:04:58']); // deadline + grace passed by 10:04:59.5
    Battle::query()->whereKey($countdown->id)->update(['starts_at' => '2026-10-30 10:04:59', 'round_deadline_at' => '2026-10-30 10:05:29']); // the countdown ends just before the run
    DB::table('battle_participants')->whereIn('battle_id', [$game->id, $countdown->id])->update(['last_seen_at' => '2026-10-30 10:04:50']); // everybody is here

    $doneAt = BattleKit::fresh($done)->finished_at?->toDateTimeString();
    CarbonImmutable::setTestNow('2026-10-30 10:04:59.5');
    $this->artisan('practice:battles-sweep')->expectsOutput('Battles swept: 3')->assertSuccessful();

    expect(BattleKit::fresh($expired)->status)->toBe(BattleStatus::Closed)
        ->and(BattleKit::fresh($expired)->end_reason)->toBe(BattleEndReason::InviteExpired)
        ->and(BattleKit::fresh($expired)->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(BattleKit::fresh($fresh)->status)->toBe(BattleStatus::Invited)
        ->and([BattleKit::fresh($countdown)->status, BattleKit::fresh($countdown)->phase])->toBe([BattleStatus::Playing, BattlePhase::Answering])
        ->and(BattleKit::fresh($game)->phase)->toBe(BattlePhase::Reveal)
        ->and(BattleAnswer::query()->where('battle_id', $game->id)->where('verdict', 'timeout')->sole()->learner_id)->toBe($friend->id)
        ->and(BattleKit::fresh($game)->participant($me->id)->score)->toBe(100)
        ->and(BattleKit::fresh($done)->finished_at?->toDateTimeString())->toBe($doneAt) // a match that is over is not touched
        ->and(ProductEvent::query()->where('name', 'battle.closed')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'battle.closed')->sole()->props)->toEqual(['battle_id' => $expired->id, 'end_reason' => 'invite_expired']);

    // Nothing is due any more: a second run changes nothing and raises nothing.
    $this->artisan('practice:battles-sweep')->expectsOutput('Battles swept: 0')->assertSuccessful();
    expect(ProductEvent::query()->where('name', 'battle.closed')->count())->toBe(1);

    // A second later the fresh invitation is past its time too.
    CarbonImmutable::setTestNow('2026-10-30 10:05:00');
    $this->artisan('practice:battles-sweep')->expectsOutput('Battles swept: 1')->assertSuccessful();
    expect(BattleKit::fresh($fresh)->end_reason)->toBe(BattleEndReason::InviteExpired);
});

it('goes on past a match that throws: the failure is reported, the other matches are moved, the run returns what it changed (Ж3)', function (): void {
    Storage::fake('practice_audio');
    Exceptions::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $a = Learner::factory()->create();
    $b = Learner::factory()->create(['interface_locale' => 'uz']);
    $ab = Friendship::factory()->between($a, $b)->create();

    $first = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    // A corrupt row between the two healthy ones: a game without its participants (the clock cannot tell who is who and throws).
    $corrupt = Battle::factory()->create([
        'challenger_id' => Learner::factory()->create()->id, 'opponent_id' => Learner::factory()->create()->id,
        'status' => BattleStatus::Playing, 'phase' => BattlePhase::Answering, 'current_round' => 1,
        'accepted_at' => '2026-10-30 10:00:00', 'starts_at' => '2026-10-30 10:00:03', 'round_deadline_at' => '2026-10-30 10:00:33',
    ]);
    $second = BattleKit::playing($a, $b, $ab, PracticeMode::Collocation, $course); // starts 3 s after the first: its round 1 ends at 10:00:36
    expect($corrupt->id)->toBeGreaterThan($first->id)->toBeLessThan($second->id);
    DB::table('battle_participants')->whereIn('battle_id', [$first->id, $second->id])->update(['last_seen_at' => '2026-10-30 10:00:37']);

    // Past the deadline and the grace of round 1 for all three: the two healthy matches get their time-outs, the corrupt one is reported.
    CarbonImmutable::setTestNow('2026-10-30 10:00:38');
    $this->artisan('practice:battles-sweep')->expectsOutput('Battles swept: 2')->assertSuccessful();

    Exceptions::assertReported(fn (LogicException $e): bool => str_contains($e->getMessage(), "battle {$corrupt->id}"));
    expect(BattleKit::fresh($first)->phase)->toBe(BattlePhase::Reveal)
        ->and(BattleKit::fresh($second)->phase)->toBe(BattlePhase::Reveal)
        ->and(BattleAnswer::query()->whereIn('battle_id', [$first->id, $second->id])->where('verdict', 'timeout')->count())->toBe(4)
        ->and(BattleKit::fresh($corrupt)->phase)->toBe(BattlePhase::Answering); // untouched: its transaction rolled back

    // A match that is not due by the clock is not even locked: the run over two games in their reveal writes nothing (the corrupt one is reported again, and only that).
    CarbonImmutable::setTestNow('2026-10-30 10:00:39');
    $queries = QueryLog::during(fn () => Artisan::call('practice:battles-sweep'));
    expect(QueryLog::matching($queries, '/^(update|insert)/i'))->toBe([])
        ->and(Artisan::output())->toContain('Battles swept: 0');
});

it('voids the matches that are on when the practice is switched off, and leaves the invitations to expire by themselves', function (): void {
    Storage::fake('practice_audio');
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $other = Learner::factory()->create();
    $third = Learner::factory()->create(['interface_locale' => 'uz']);
    $pair = Friendship::factory()->between($other, $third)->create();

    $game = BattleKit::playing($me, $friend, $friendship, PracticeMode::Pairs, $course);
    $invited = BattleKit::invite($other, $pair, PracticeMode::Collocation, $course);

    config(['practice.access' => 'off']);
    $this->artisan('practice:battles-sweep')->expectsOutput('Battles swept: 1')->assertSuccessful();

    $row = BattleKit::fresh($game);
    expect([$row->status, $row->end_reason, $row->meta['voided_reason'], $row->winner_learner_id])->toBe([BattleStatus::Closed, BattleEndReason::Voided, 'practice_off', null])
        ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(BattleKit::fresh($invited)->status)->toBe(BattleStatus::Invited)
        ->and(ProductEvent::query()->where('name', 'battle.closed')->sole()->props)->toEqual(['battle_id' => $game->id, 'end_reason' => 'voided']);

    // Still off a day later: the invitation has expired the ordinary way; nothing else to void.
    CarbonImmutable::setTestNow('2026-10-31 10:00:00');
    $this->artisan('practice:battles-sweep')->expectsOutput('Battles swept: 1')->assertSuccessful();
    expect(BattleKit::fresh($invited)->end_reason)->toBe(BattleEndReason::InviteExpired);
});
