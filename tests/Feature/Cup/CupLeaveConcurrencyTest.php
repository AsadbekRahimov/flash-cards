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
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
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
