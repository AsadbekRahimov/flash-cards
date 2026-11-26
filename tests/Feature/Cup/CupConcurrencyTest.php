<?php

declare(strict_types=1);

use App\Domain\Cup\DTO\BestResult;
use App\Domain\Cup\DTO\DayScore;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Cup\Services\CupScoreBook;
use App\Domain\Cup\Support\CupScoring;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupKit;
use Tests\Support\Game\GameRace;

/*
 * docs/33 §5.3, §5.6, §3.1 (K4, K5): the real races of the cup - forked processes, each with its own PostgreSQL connection, released
 * at the same instant (GameRace). Committed data (DatabaseTruncation); a refusal of the cup is a result (`refused`), anything else
 * thrown is a crash; a deadlock or an endless wait is a crash too (lock_timeout 8 s).
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
    CupKit::open();
    CupKit::course();
    Cache::flush();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/** A task of the race: a refusal of the cup is a value, not a crash. */
function cupTask(Closure $task): Closure
{
    return static function () use ($task): mixed {
        try {
            return $task();
        } catch (CupException $e) {
            return ['refused' => $e->errorCode];
        }
    };
}

/** @param list<array<string, mixed>> $results @return list<mixed> */
function cupValues(array $results): array
{
    expect(GameRace::crashes($results))->toBe([]);

    return array_map(static fn (array $r): mixed => $r['value'] ?? null, $results);
}

it('makes one attempt and one snapshot when the same participant starts twice at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['snapshot' => null, 'built_at' => null]);
    $cupId = $cup->id;
    $learnerId = $a->id;

    $values = cupValues(GameRace::run(array_fill(0, 3, cupTask(fn (): int => app(CupRoundService::class)
        ->startToday(Learner::query()->findOrFail($learnerId), Cup::query()->with('telegramGroup')->findOrFail($cupId), $now)->view->attemptId))));

    expect(array_unique($values))->toHaveCount(1)
        ->and(CupRoundAttempt::query()->count())->toBe(1)
        ->and(DB::table('cup_days')->where('cup_id', $cupId)->whereNotNull('snapshot')->count())->toBe(1)
        ->and(DB::table('cup_day_scores')->count())->toBe(1)
        ->and(CupParticipant::query()->where('learner_id', $learnerId)->value('days_played'))->toBe(1);
});

it('builds the snapshot of the day once when the first starts of four participants come at the same instant', function (): void {
    ['cup' => $cup, 'learners' => $learners, 'start' => $now] = CupKit::running(4);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['snapshot' => null, 'built_at' => null]);
    $cupId = $cup->id;

    $tasks = [];
    foreach ($learners as $learner) {
        $id = $learner->id;
        $tasks[] = cupTask(fn (): array => CupRoundAttempt::query()->findOrFail(app(CupRoundService::class)
            ->startToday(Learner::query()->findOrFail($id), Cup::query()->with('telegramGroup')->findOrFail($cupId), $now)->view->attemptId)->order);
    }
    $orders = cupValues(GameRace::run($tasks));

    $sets = array_map(static function (array $order): string {
        sort($order);

        return implode(',', $order);
    }, $orders);
    expect(array_unique($sets))->toHaveCount(1, 'everybody plays the same tasks')
        ->and(CupRoundAttempt::query()->count())->toBe(4);
});

it('accepts one of two answers to the same task sent at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = app(CupRoundService::class)->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $elementId = (string) $view->current['id'];
    $answer = CupKit::rightAnswer($dayId, $elementId);
    $attemptId = $view->attemptId;
    $learnerId = $a->id;

    $values = cupValues(GameRace::run(array_fill(0, 3, cupTask(fn (): int => app(CupRoundService::class)
        ->answer(Learner::query()->findOrFail($learnerId), $attemptId, (string) Str::uuid(), $elementId, $answer, $now->addSeconds(5))->body['points']))));

    sort($values, SORT_REGULAR);
    expect(array_filter($values, 'is_int'))->toBe([2])
        ->and(DB::table('cup_round_answers')->count())->toBe(1)
        ->and(CupRoundAttempt::query()->findOrFail($attemptId)->points)->toBe(2)
        ->and(DB::table('cup_day_scores')->value('round_points'))->toBe(2);
    foreach (array_filter($values, 'is_array') as $refused) {
        expect($refused['refused'])->toBe('answer_already_accepted');
    }
});

it('answers a request_id repeated at the same instant once: the same body, one row, one credit', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = app(CupRoundService::class)->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $elementId = (string) $view->current['id'];
    $answer = CupKit::rightAnswer($dayId, $elementId);
    $requestId = (string) Str::uuid();
    $attemptId = $view->attemptId;
    $learnerId = $a->id;

    $values = cupValues(GameRace::run(array_fill(0, 3, cupTask(fn (): array => app(CupRoundService::class)
        ->answer(Learner::query()->findOrFail($learnerId), $attemptId, $requestId, $elementId, $answer, $now->addSeconds(5))->body))));

    expect(array_unique(array_map('json_encode', $values)))->toHaveCount(1)
        ->and(DB::table('cup_round_answers')->count())->toBe(1)
        ->and(DB::table('cup_day_scores')->value('round_points'))->toBe(2);
});

it('keeps an answer and the closing of the expired attempt consistent when they meet: no deadlock, the points of the accepted answer stay', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = app(CupRoundService::class)->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $elementId = (string) $view->current['id'];
    $answer = CupKit::rightAnswer($dayId, $elementId);
    $attemptId = $view->attemptId;
    $learnerId = $a->id;
    $edge = $now->addMinutes(10)->addSecond(); // inside the grace for the answer, past it for the sweep below
    $sweepAt = $now->addMinutes(10)->addSeconds(3); // the sweep compares whole seconds, never closing early

    $values = cupValues(GameRace::run([
        cupTask(fn (): int => app(CupRoundService::class)->answer(Learner::query()->findOrFail($learnerId), $attemptId, (string) Str::uuid(), $elementId, $answer, $edge)->body['points']),
        cupTask(fn (): int => app(CupRoundService::class)->closeExpired($sweepAt, 100)),
        cupTask(fn (): int => app(CupRoundService::class)->closeExpired($sweepAt, 100)),
    ]));

    $attempt = CupRoundAttempt::query()->findOrFail($attemptId);
    expect($attempt->status->value)->toBe('finished')
        ->and($attempt->finish_reason?->value)->toBe('expired')
        ->and($values[1] + $values[2])->toBe(1, 'closed exactly once');
    $accepted = is_int($values[0]);
    expect($attempt->points)->toBe($accepted ? 2 : 0)
        ->and((int) DB::table('cup_day_scores')->sum('round_points'))->toBe($accepted ? 2 : 0);
    if (! $accepted) {
        expect($values[0]['refused'])->toBe('cup_round_expired');
    }
});

it('starts a cup once when two schedulers run at the same instant, and finishes it once', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    foreach ([1, 2] as $ignored) {
        $learner = Learner::factory()->create();
        CupKit::member($learner);
        app(CupParticipationService::class)->join($cup, $learner);
    }
    $midnight = CarbonImmutable::instance($cup->starts_at)->addMinute();

    $starts = cupValues(GameRace::run(array_fill(0, 3, cupTask(fn (): array => app(CupScheduler::class)->advance($midnight)))));
    expect(array_sum(array_column($starts, 'started')))->toBe(1)
        ->and(array_sum(array_column($starts, 'failed')))->toBe(0)
        ->and(DB::table('cup_days')->where('cup_id', $cup->id)->count())->toBe(7)
        ->and(Cup::query()->findOrFail($cup->id)->status->value)->toBe('running');

    $end = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);
    $finishes = cupValues(GameRace::run(array_fill(0, 3, cupTask(fn (): array => app(CupScheduler::class)->advance($end)))));
    expect(array_sum(array_column($finishes, 'finished')))->toBe(1)
        ->and(DB::table('cup_messages')->where('cup_id', $cup->id)->where('dedupe_key', 'final')->count())->toBe(1)
        ->and(DB::table('product_events')->where('name', 'cup.finished')->count())->toBe(1);
});

it('orders a join and the start of the cup at the same instant: the newcomer is either counted at the start or plays from the current day', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $first = Learner::factory()->create();
    CupKit::member($first);
    app(CupParticipationService::class)->join($cup, $first);
    $second = Learner::factory()->create();
    CupKit::member($second);
    $midnight = CarbonImmutable::instance($cup->starts_at);
    $cupId = $cup->id;
    $secondId = $second->id;

    $values = cupValues(GameRace::run([
        cupTask(fn (): bool => app(CupParticipationService::class)->join(Cup::query()->with('telegramGroup')->findOrFail($cupId), Learner::query()->findOrFail($secondId), null, $midnight)->created),
        cupTask(fn (): string => app(CupLifecycleService::class)->start($cupId, $midnight)),
    ]));

    $cup = Cup::query()->findOrFail($cupId);
    expect($values[0])->toBeTrue()
        ->and(CupParticipant::query()->where('cup_id', $cupId)->count())->toBe(2)
        ->and(CupParticipant::query()->where('learner_id', $secondId)->value('first_day'))->toBe(1);
    if ($values[1] === 'started') {
        expect($cup->status->value)->toBe('running')->and(DB::table('cup_days')->where('cup_id', $cupId)->count())->toBe(7);
    } else {
        expect($values[1])->toBe('shifted')->and($cup->status->value)->toBe('scheduled')->and(DB::table('cup_days')->where('cup_id', $cupId)->count())->toBe(0);
    }
});

it('makes one draft when "create a cup" is pressed three times at the same instant', function (): void {
    $group = CupKit::group();
    $groupId = $group->id;

    $values = cupValues(GameRace::run(array_fill(0, 3, cupTask(fn (): int => app(CupLifecycleService::class)->propose(TelegramGroup::query()->findOrFail($groupId), 42)->cup->id))));

    expect(array_unique($values))->toHaveCount(1)
        ->and(Cup::query()->where('telegram_group_id', $groupId)->count())->toBe(1)
        ->and(DB::table('product_events')->where('name', 'cup.proposed')->count())->toBe(1);
});

it('ends a publication and a cancel at the same instant in a consistent cup: canceled, the chat free', function (): void {
    $group = CupKit::group();
    $cup = app(CupLifecycleService::class)->propose($group, 42)->cup;
    $admin = CupKit::admin();
    $cupId = $cup->id;

    $values = cupValues(GameRace::run([
        cupTask(fn (): string => app(CupLifecycleService::class)->publish(Cup::query()->with('telegramGroup')->findOrFail($cupId), $admin)->status->value),
        cupTask(fn (): bool => app(CupLifecycleService::class)->cancelByChatAdmin(Cup::query()->with('telegramGroup')->findOrFail($cupId), $admin)),
    ]));

    $cup = Cup::query()->findOrFail($cupId);
    expect($values[1])->toBeTrue()
        ->and($cup->status->value)->toBe('canceled')
        ->and($cup->active_group_id)->toBeNull()
        ->and(in_array($values[0], ['scheduled', ['refused' => 'cup_closed']], true))->toBeTrue();
});

it('keeps the finish and an answer at the same instant consistent: the places match the scores the participants hold', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $start] = CupKit::running(2);
    $lastDay = CarbonImmutable::instance($cup->ends_at)->subMinutes(5);
    $pa = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    app(CupScoreBook::class)->addRoundPoints($cup->id, $pa->id, 7, 2, 5, $lastDay);
    Cache::flush();
    $view = app(CupRoundService::class)->startToday($b, $cup, $lastDay)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $elementId = (string) $view->current['id'];
    $answer = CupKit::rightAnswer($dayId, $elementId);
    $attemptId = $view->attemptId;
    $bId = $b->id;
    $cupId = $cup->id;

    cupValues(GameRace::run([
        cupTask(fn (): int => app(CupRoundService::class)->answer(Learner::query()->findOrFail($bId), $attemptId, (string) Str::uuid(), $elementId, $answer, $lastDay->addMinute())->body['points']),
        cupTask(fn (): bool => app(CupLifecycleService::class)->finish($cupId, CarbonImmutable::instance($cup->ends_at)->addMinutes(31))),
    ]));

    expect(Cup::query()->findOrFail($cupId)->status->value)->toBe('finished');
    $rows = CupParticipant::query()->where('cup_id', $cupId)->get();
    foreach ($rows as $row) {
        $days = DB::table('cup_day_scores')->where('participant_id', $row->id)->get()->map(fn ($d) => new DayScore((int) $d->day, (int) $d->round_points, (int) $d->duel_points))->all();
        $best = CupScoring::best($days, 5);
        expect($best)->toBeInstanceOf(BestResult::class)->and($row->best_total)->toBe($best->total);
    }
    $ranks = CupScoring::ranks($rows->mapWithKeys(fn ($r) => [$r->id => ['total' => $r->best_total, 'round' => $r->best_round_points]])->all());
    foreach ($rows as $row) {
        expect($row->final_rank)->toBe($ranks[$row->id]);
    }
});
