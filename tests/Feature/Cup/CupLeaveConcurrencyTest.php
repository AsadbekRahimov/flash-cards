<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Cup\Services\CupScoreBook;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Battle;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\Game\GameRace;

/*
 * docs/33 §11.12: the real races of «Выйти из кубка» - forked processes, each with its own PostgreSQL connection, released at the same
 * instant (GameRace). The leave takes the cup FOR KEY SHARE, then the open duels, then the participant - the one order of the cup; a
 * round answer (attempt → participant), an acceptance (duel → slots → battle) and the finish (cup FOR UPDATE → participants) meet it
 * without a deadlock (lock_timeout 8 s: an endless wait is a crash) and every outcome is a consistent one.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    if (config('cache.default') !== 'redis') {
        $this->markTestSkipped('Needs a cache shared by the processes (Redis) for the locks of the membership check.');
    }
    Cache::flush();
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class, SendCupDuelDmJob::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/** A task of the race: a refusal is a value, not a crash. */
function leaveRaceTask(Closure $task): Closure
{
    return static function () use ($task): mixed {
        try {
            return $task();
        } catch (CupException|BattleException|PracticeException $e) {
            return ['refused' => $e->errorCode];
        }
    };
}

/** @param list<array<string, mixed>> $results @return list<mixed> */
function leaveRaceValues(array $results): array
{
    expect(GameRace::crashes($results))->toBe([]);

    return array_map(static fn (array $r): mixed => $r['value'] ?? null, $results);
}

function leaveRaceLeave(int $cupId, int $learnerId, CarbonImmutable $at): Closure
{
    return leaveRaceTask(static function () use ($cupId, $learnerId, $at): string {
        $cup = Cup::query()->with('telegramGroup')->findOrFail($cupId);
        $learner = Learner::query()->findOrFail($learnerId);
        $participant = CupParticipant::query()->where('cup_id', $cupId)->where('learner_id', $learnerId)->firstOrFail();

        return 'left:'.app(CupParticipationService::class)->leave($cup, $participant, $learner, $at)->status->value;
    });
}

it('keeps a round answer and the leave at the same instant: no deadlock, the answer counts, the participant is gone for good', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    $view = app(CupRoundService::class)->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $elementId = (string) $view->current['id'];
    $answer = CupKit::rightAnswer($dayId, $elementId);
    $attemptId = $view->attemptId;
    $aId = $a->id;
    $at = $now->addSeconds(5);

    $values = leaveRaceValues(GameRace::run([
        leaveRaceTask(fn (): int => app(CupRoundService::class)->answer(Learner::query()->findOrFail($aId), $attemptId, (string) Str::uuid(), $elementId, $answer, $at)->body['points']),
        leaveRaceLeave($cup->id, $aId, $at),
    ]));

    $row = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $aId)->firstOrFail();
    expect($values)->toContain(2)->toContain('left:left')
        ->and($row->status)->toBe(CupParticipantStatus::Left)
        ->and($row->withdrawn_at)->not->toBeNull()
        ->and($row->best_total)->toBe(2)
        ->and((int) DB::table('cup_day_scores')->where('participant_id', $row->id)->value('round_points'))->toBe(2);
});

it('decides the acceptance of my open challenge and my leave at the same instant one way only', function (int $delay): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupDuelKit::running(2);
    $duel = CupDuelKit::challenge($a, $cup, null, public: true, now: $now)->duel;
    $at = $now->addMinute();
    $bId = $b->id;

    $values = leaveRaceValues(GameRace::run([
        leaveRaceTask(function () use ($bId, $duel, $at, $delay): string {
            usleep($delay);

            return 'accepted:'.app(CupDuelService::class)->accept(Learner::query()->findOrFail($bId), $duel->id, true, $at)->duel->status->value;
        }),
        leaveRaceLeave($cup->id, $a->id, $at),
    ]));

    $final = CupDuel::query()->findOrFail($duel->id);
    $slots = DB::table('cup_duel_slots')->where('cup_duel_id', $duel->id)->count();
    expect($values)->toContain('left:left');
    if ($final->status === CupDuelStatus::Accepted) {
        // The acceptance came first: the match plays out (§11.9.2 п. 5).
        expect($values)->toContain('accepted:accepted')
            ->and(Battle::query()->count())->toBe(1)
            ->and($slots)->toBe(2);
    } else {
        // The leave came first: the challenge is closed, nothing was opened.
        expect($final->status)->toBe(CupDuelStatus::Canceled)
            ->and($final->end_reason?->value)->toBe('left')
            ->and($values)->toContain(['refused' => 'cup_duel_closed'])
            ->and(Battle::query()->count())->toBe(0)
            ->and($slots)->toBe(0)
            ->and(DB::table('cup_messages')->where('dedupe_key', "duel:{$duel->id}:end")->count())->toBe(1);
    }
})->with(['together' => [0], 'acceptance a moment later' => [30_000], 'leave first by the clock' => [80_000]]);

it('keeps the finish and the leave at the same instant consistent: no deadlock, the places written for everybody', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $start] = CupDuelKit::running(2);
    $lastDay = CarbonImmutable::instance($cup->ends_at)->subMinutes(5);
    $pa = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $a->id)->firstOrFail();
    app(CupScoreBook::class)->addRoundPoints($cup->id, $pa->id, 7, 4, 5, $lastDay);
    $cupId = $cup->id;
    $finishAt = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);

    $values = leaveRaceValues(GameRace::run([
        leaveRaceTask(fn (): bool => app(CupLifecycleService::class)->finish($cupId, $finishAt)),
        leaveRaceLeave($cupId, $a->id, $finishAt),
    ]));

    expect(Cup::query()->findOrFail($cupId)->status->value)->toBe('finished')
        ->and($values)->toContain(true);
    $row = $pa->fresh();
    if (in_array('left:left', $values, true)) {
        expect($row->withdrawn_at)->not->toBeNull()->and($row->status)->toBe(CupParticipantStatus::Left);
    } else {
        expect($values)->toContain(['refused' => 'cup_closed'])
            ->and($row->withdrawn_at)->toBeNull();
    }
    // The leave does not take a place away: everybody is ranked, the one with the points first.
    expect($row->final_rank)->toBe(1)
        ->and(CupParticipant::query()->where('cup_id', $cupId)->whereNull('final_rank')->count())->toBe(0);
});

it('answers a double tap of the leave once: one withdrawal, one event', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    $at = $now->addMinute();

    $values = leaveRaceValues(GameRace::run([leaveRaceLeave($cup->id, $a->id, $at), leaveRaceLeave($cup->id, $a->id, $at), leaveRaceLeave($cup->id, $a->id, $at)]));

    expect($values)->toBe(['left:left', 'left:left', 'left:left'])
        ->and(DB::table('product_events')->where('name', 'cup.left')->count())->toBe(1);
});

/*
 * docs/33 §11.12 «нет открытого вызова с вышедшим»: a challenge and the leave of one of its two players at the same moment. Whatever
 * the interleaving, after both commits no `open` duel has a withdrawn (or inactive) challenger or addressee: either the challenge was
 * refused (`cup_duel_unavailable`), or it landed first and the leave closed it (`canceled/left`). The two windows of the review are made
 * certain by a BEFORE trigger that holds a statement for 0.4 s while the other side runs:
 *  - `slow_duel_insert` - the INSERT of the duel (before its foreign keys are checked) while the leave, a moment later, runs whole;
 *  - `slow_withdrawal` - the UPDATE of the participant that withdraws him (his row locked) while the challenge, a moment later, inserts.
 * Repeated (CUP_LEAVE_CHALLENGE_RACE_REPEAT, 50 for the review) - one 40P01 is a crash of the race.
 */
function leaveRaceSlow(string $kind): void
{
    if ($kind === 'slow_duel_insert') {
        DB::unprepared('CREATE OR REPLACE FUNCTION test_slow_cup_duel_insert() RETURNS trigger AS $$ BEGIN PERFORM pg_sleep(0.4); RETURN NEW; END $$ LANGUAGE plpgsql');
        DB::unprepared('CREATE TRIGGER test_slow_cup_duel_insert BEFORE INSERT ON cup_duels FOR EACH ROW EXECUTE FUNCTION test_slow_cup_duel_insert()');
    } elseif ($kind === 'slow_withdrawal') {
        DB::unprepared('CREATE OR REPLACE FUNCTION test_slow_withdrawal() RETURNS trigger AS $$ BEGIN IF NEW.withdrawn_at IS NOT NULL AND OLD.withdrawn_at IS NULL THEN PERFORM pg_sleep(0.4); END IF; RETURN NEW; END $$ LANGUAGE plpgsql');
        DB::unprepared('CREATE TRIGGER test_slow_withdrawal BEFORE UPDATE ON cup_participants FOR EACH ROW EXECUTE FUNCTION test_slow_withdrawal()');
    }
}

function leaveRaceUnslow(): void
{
    DB::reconnect();
    DB::unprepared('DROP TRIGGER IF EXISTS test_slow_cup_duel_insert ON cup_duels');
    DB::unprepared('DROP FUNCTION IF EXISTS test_slow_cup_duel_insert()');
    DB::unprepared('DROP TRIGGER IF EXISTS test_slow_withdrawal ON cup_participants');
    DB::unprepared('DROP FUNCTION IF EXISTS test_slow_withdrawal()');
}

/** No open duel of the cup whose challenger or addressee is not an active participant any more. */
function leaveRaceOpenWithGone(int $cupId): int
{
    return DB::table('cup_duels as d')
        ->join('cup_participants as c', 'c.id', '=', 'd.challenger_participant_id')
        ->leftJoin('cup_participants as o', 'o.id', '=', 'd.opponent_participant_id')
        ->where('d.cup_id', $cupId)
        ->where('d.status', CupDuelStatus::Open->value)
        ->where(fn ($q) => $q->whereNotNull('c.withdrawn_at')->orWhere('c.status', '<>', 'active')
            ->orWhereNotNull('o.withdrawn_at')->orWhere('o.status', '<>', 'active'))
        ->count();
}

it('never leaves an open challenge to a participant who leaves the cup at the same moment', function (string $slow, int $challengeDelayUs, int $leaveDelayUs, bool $public): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(2);
    $at = $now->addMinute();
    $aId = $a->id;
    $cupId = $cup->id;
    $pbId = $pb->id;
    leaveRaceSlow($slow);

    try {
        $values = leaveRaceValues(GameRace::run([
            leaveRaceTask(function () use ($aId, $cupId, $pbId, $at, $challengeDelayUs, $public): string {
                usleep($challengeDelayUs);
                $outcome = app(CupDuelService::class)->challenge(Learner::query()->findOrFail($aId), CupDuelKit::cup($cupId), $pbId, $public, PracticeMode::Pairs, (string) Str::uuid(), $at);

                return 'challenged:'.$outcome->duel->id;
            }),
            leaveRaceTask(function () use ($cupId, $b, $at, $leaveDelayUs): mixed {
                usleep($leaveDelayUs);

                return leaveRaceLeave($cupId, $b->id, $at)();
            }),
        ]));
    } finally {
        leaveRaceUnslow();
    }

    expect($values)->toContain('left:left')
        ->and(leaveRaceOpenWithGone($cupId))->toBe(0);
    $duel = CupDuel::query()->where('cup_id', $cupId)->first();
    if ($duel === null) {
        expect($values)->toContain(['refused' => 'cup_duel_unavailable']);
    } else {
        expect($duel->status)->toBe(CupDuelStatus::Canceled)
            ->and($duel->end_reason?->value)->toBe('left')
            ->and($duel->open_challenger_id)->toBeNull();
        if ($public) {
            expect(DB::table('cup_messages')->where('dedupe_key', "duel:{$duel->id}:end")->count())->toBe(1);
        }
    }
})->with([
    'the insert of the duel held, the leave a moment later' => ['slow_duel_insert', 0, 100_000, false],
    'the withdrawal held, the challenge a moment later' => ['slow_withdrawal', 100_000, 0, false],
    'the same instant' => ['none', 0, 0, false],
    'public: the insert held' => ['slow_duel_insert', 0, 100_000, true],
    'public: the withdrawal held' => ['slow_withdrawal', 100_000, 0, true],
])->repeat(max(1, (int) (getenv('CUP_LEAVE_CHALLENGE_RACE_REPEAT') ?: 3)));

it('never leaves an open challenge of a participant who leaves the cup while it is being made', function (string $slow, int $challengeDelayUs, int $leaveDelayUs, bool $public, bool $addressed, bool $telegramDown): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(2);
    $at = $now->addMinute();
    if ($telegramDown) {
        // Telegram out of reach: the door lets him in by the row read before the transaction (`stale_ok_hours`) - only the INSERT
        // can see that he left in the meantime.
        CupKit::memberFails($a, new TelegramApiException('cURL error 28: timed out'));
        Cache::flush();
    }
    $aId = $a->id;
    $cupId = $cup->id;
    $pbId = $addressed ? $pb->id : null;
    leaveRaceSlow($slow);

    try {
        $values = leaveRaceValues(GameRace::run([
            leaveRaceTask(function () use ($aId, $cupId, $pbId, $at, $challengeDelayUs, $public): string {
                usleep($challengeDelayUs);
                $outcome = app(CupDuelService::class)->challenge(Learner::query()->findOrFail($aId), CupDuelKit::cup($cupId), $pbId, $public, PracticeMode::Pairs, (string) Str::uuid(), $at);

                return 'challenged:'.$outcome->duel->id;
            }),
            leaveRaceTask(function () use ($cupId, $aId, $at, $leaveDelayUs): mixed {
                usleep($leaveDelayUs);

                return leaveRaceLeave($cupId, $aId, $at)();
            }),
        ]));
    } finally {
        leaveRaceUnslow();
    }

    expect($values)->toContain('left:left')
        ->and(leaveRaceOpenWithGone($cupId))->toBe(0);
    $duel = CupDuel::query()->where('cup_id', $cupId)->first();
    if ($duel === null) {
        // The leave first: the door of the challenger (a fresh "member" does not bring back one who left, `cup_not_member`), or the
        // INSERT that found him gone (`cup_duel_unavailable`).
        expect(array_filter($values, 'is_array'))->toBeIn([[['refused' => 'cup_not_member']], [['refused' => 'cup_duel_unavailable']]]);
    } else {
        expect($duel->status)->toBe(CupDuelStatus::Canceled)
            ->and($duel->end_reason?->value)->toBe('left');
        if ($public) {
            expect(DB::table('cup_messages')->where('dedupe_key', "duel:{$duel->id}:end")->count())->toBe(1);
        }
    }
})->with([
    'public to anybody: the insert held, the leave a moment later' => ['slow_duel_insert', 0, 100_000, true, false, false],
    'public to anybody: the withdrawal held, the challenge a moment later' => ['slow_withdrawal', 100_000, 0, true, false, false],
    'private: the insert held' => ['slow_duel_insert', 0, 100_000, false, true, false],
    'private: the withdrawal held' => ['slow_withdrawal', 100_000, 0, false, true, false],
    'the same instant' => ['none', 0, 0, true, false, false],
    'Telegram down: the withdrawal held, the challenge a moment later' => ['slow_withdrawal', 100_000, 0, true, true, true],
    'Telegram down: the insert held' => ['slow_duel_insert', 0, 100_000, false, true, true],
])->repeat(max(1, (int) (getenv('CUP_LEAVE_CHALLENGE_RACE_REPEAT') ?: 3)));

/*
 * The order of the locks of a challenge (docs/33 §11.12): the cup BEFORE the participants, in the INSERT that reads them. The finish
 * holds the cup `FOR UPDATE` and then locks every participant - a challenge that held a participant `FOR SHARE` and waited for the cup
 * would be a 40P01. The clocks of the two are their own (the challenge in the last day, the finish after the settle time): only the
 * locks are raced here.
 */
it('never deadlocks a challenge against the finish of the cup', function (string $slow, int $challengeDelayUs, int $finishDelayUs): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(2);
    $cupId = $cup->id;
    $aId = $a->id;
    $pbId = $pb->id;
    $at = $now->addMinute();
    $finishAt = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);
    if ($slow === 'slow_finish') {
        DB::unprepared("CREATE OR REPLACE FUNCTION test_slow_cup_finish() RETURNS trigger AS \$\$ BEGIN IF NEW.status = 'finished' AND OLD.status <> 'finished' THEN PERFORM pg_sleep(0.4); END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER test_slow_cup_finish BEFORE UPDATE ON cups FOR EACH ROW EXECUTE FUNCTION test_slow_cup_finish()');
    } else {
        leaveRaceSlow($slow);
    }

    try {
        $values = leaveRaceValues(GameRace::run([
            leaveRaceTask(function () use ($aId, $cupId, $pbId, $at, $challengeDelayUs): string {
                usleep($challengeDelayUs);

                return 'challenged:'.app(CupDuelService::class)->challenge(Learner::query()->findOrFail($aId), CupDuelKit::cup($cupId), $pbId, false, PracticeMode::Pairs, (string) Str::uuid(), $at)->duel->status->value;
            }),
            leaveRaceTask(function () use ($cupId, $finishAt, $finishDelayUs): bool {
                usleep($finishDelayUs);

                return app(CupLifecycleService::class)->finish($cupId, $finishAt);
            }),
        ]));
    } finally {
        leaveRaceUnslow();
        DB::unprepared('DROP TRIGGER IF EXISTS test_slow_cup_finish ON cups');
        DB::unprepared('DROP FUNCTION IF EXISTS test_slow_cup_finish()');
    }

    expect($values)->toContain(true)
        ->and(Cup::query()->findOrFail($cupId)->status->value)->toBe('finished');
    if (CupDuel::query()->where('cup_id', $cupId)->exists()) {
        // The challenge before the finish (its cup still running under the lock).
        expect($values)->toContain('challenged:open');
    } else {
        // The finish first: the challenge read the cup under its lock - not running any more.
        expect($values[0])->toBeArray();
    }
})->with([
    'the insert of the duel held, the finish a moment later' => ['slow_duel_insert', 0, 100_000],
    'the finish held, the challenge a moment later' => ['slow_finish', 100_000, 0],
    'the same instant' => ['none', 0, 0],
])->repeat(max(1, (int) (getenv('CUP_LEAVE_CHALLENGE_RACE_REPEAT') ?: 3)));
