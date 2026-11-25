<?php

declare(strict_types=1);

/*
 * Real races of "all time" (docs/32 §12.11, S10) on PostgreSQL: forked workers, each with its own connection and transaction, released
 * at the same instant (Tests\Support\Game\GameRace: lock_timeout 8 s - a deadlock or an endless wait is a crash, not a hang).
 *
 *   - the recompute during the credits: answers of many learners while the scheduled run, the reconcile and the hooks of single
 *     learners recompute the same rows - nobody waits for anybody of the answer, no deadlock between the recomputes (one lock order),
 *     and the next scheduled run (the margin) brings every row to its source;
 *   - the close of the month during the recompute: the close (its barrier, the full upsert, the ranks, the candidates) while the
 *     reconcile, the scheduled run and a change of the region run - the month closes once, the totals equal their source.
 * Committed rows: DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreTotalsService;
use App\Domain\Learners\Services\LearnerRegionService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Models\LeagueMonth;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScoreTotal;
use App\Models\Lexeme;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Game\GameRace;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races "all time" on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-10 12:00', 'Asia/Tashkent')->utc());
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.versions.2.starts_on' => '2027-02-01',
        'leagues.all_time.reconcile_batch' => 100,
    ]);
    Cache::forget(ScoreTotalsService::MARKER);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/** A due repetition of the lexeme answered in its own transaction (3 points to the day of the learner). */
function atrrAnswer(int $learnerId, int $lexemeId, CarbonImmutable $now): string
{
    return DB::transaction(fn (): string => app(AnswerScoreRecorder::class)->record(new ScoredAnswer(
        $learnerId, $lexemeId, 1, ScoreSource::Training, 7, AnswerVerdict::Passed,
        new CardStateBefore(RepetitionState::Review, 3, $now->subDays(3)->subHour()), Plan::Free, $now,
    ))->reason->value);
}

/**
 * Every row of `learner_score_totals` against its source, computed here independently of the service.
 *
 * @return list<string> the differences (empty = equal)
 */
function atrrDrift(): array
{
    $expected = [];
    foreach (LearnerScoreDay::query()->where('month_version', '>=', 2)->get() as $day) {
        $key = $day->learner_id.':'.$day->month_category->value;
        $expected[$key] ??= ['points' => 0, 'days' => 0];
        $expected[$key]['points'] += $day->month_points;
        $expected[$key]['days'] += $day->month_points > 0 ? 1 : 0;
    }

    $actual = [];
    foreach (LearnerScoreTotal::query()->get() as $row) {
        $actual[$row->learner_id.':'.$row->category->value] = ['points' => $row->points, 'days' => $row->active_days];
    }
    ksort($expected);
    ksort($actual);

    $drift = [];
    foreach ($expected + $actual as $key => $_) {
        if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
            $drift[] = $key.' expected '.json_encode($expected[$key] ?? null).' got '.json_encode($actual[$key] ?? null);
        }
    }

    return $drift;
}

it('recomputes while the learners answer: no deadlock, nobody waits for the answer, the next run brings every row to its source', function (): void {
    $learners = Learner::factory()->count(6)->create()->modelKeys();
    $lexemes = Lexeme::factory()->count(4)->create()->modelKeys();
    foreach ($learners as $learner) {
        foreach ($lexemes as $lexeme) {
            LearnerLexeme::factory()->create(['learner_id' => $learner, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-02-01']);
        }
        // February (closed history) - something to sum besides today.
        LearnerScoreDay::factory()->on('2027-02-10')->create(['learner_id' => $learner, 'month_points' => 40, 'week_points' => 40]);
    }
    app(ScoreTotalsService::class)->refreshRecent(CarbonImmutable::now()->subMinutes(10));

    $tasks = [];
    foreach ($learners as $i => $learner) {
        $tasks[] = function () use ($learner, $lexemes, $i): array {
            $out = [];
            foreach ($lexemes as $j => $lexeme) {
                $out[] = atrrAnswer($learner, $lexeme, CarbonImmutable::now()->addSeconds($i * 4 + $j));
            }

            return $out;
        };
        $tasks[] = fn () => app(ScoreTotalsService::class)->recomputeLearner($learner, CarbonImmutable::now()->addSeconds(2));
    }
    $tasks[] = fn (): int => app(ScoreTotalsService::class)->refreshRecent(CarbonImmutable::now()->addSeconds(1));
    $tasks[] = fn (): int => app(ScoreTotalsService::class)->reconcileAll(CarbonImmutable::now()->addSeconds(3));
    $tasks[] = fn (): int => app(ScoreTotalsService::class)->refreshRecent(CarbonImmutable::now()->addSeconds(4));
    $results = GameRace::run($tasks);

    expect(GameRace::crashes($results))->toBe([]);
    foreach ($learners as $i => $_) {
        expect($results[$i * 2]['value'])->toBe(['credited', 'credited', 'credited', 'credited']);
    }

    // The scheduled run ten minutes later: its margin covers every credit stamped before the previous run started.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));
    app(ScoreTotalsService::class)->refreshRecent(CarbonImmutable::now());

    expect(atrrDrift())->toBe([])
        ->and(LearnerScoreTotal::query()->where('category', 'free')->pluck('points')->unique()->values()->all())->toBe([52]);
});

it('closes the month while all time is recomputed and a learner changes the region: closed once, the totals equal their source', function (): void {
    $months = app(MonthlyRatingService::class);
    $february = $months->currentMonth(CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent'));
    $learners = Learner::factory()->count(20)->create(['region_code' => 'UZ-TK', 'region_changed_at' => CarbonImmutable::now()->subDays(60)]);
    foreach ($learners as $i => $learner) {
        LearnerScoreDay::factory()->on('2027-02-1'.($i % 10))->create(['learner_id' => $learner->id, 'month_points' => 10 + $i, 'week_points' => 10 + $i]);
        LearnerScoreDay::factory()->on('2027-03-02')->create(['learner_id' => $learner->id, 'month_points' => 5, 'week_points' => 5, 'month_category' => $i % 2 === 0 ? 'pro' : 'free']);
    }
    $months->refresh($february, CarbonImmutable::parse('2027-02-28 12:00', 'Asia/Tashkent'), full: true);
    $mover = $learners[3]->id;

    $tasks = [
        fn (): bool => app(MonthlyRatingService::class)->closeMonth(LeagueMonth::query()->findOrFail($february->id), CarbonImmutable::now()),
        fn (): bool => app(MonthlyRatingService::class)->closeMonth(LeagueMonth::query()->findOrFail($february->id), CarbonImmutable::now()),
        fn (): int => app(ScoreTotalsService::class)->reconcileAll(CarbonImmutable::now()),
        fn (): int => app(ScoreTotalsService::class)->refreshRecent(CarbonImmutable::now()),
        function () use ($mover): bool {
            app(LearnerRegionService::class)->set(Learner::query()->findOrFail($mover), 'UZ-SA', LearnerRegionService::VIA_SETTINGS);

            return true;
        },
    ];
    foreach ($learners->take(6) as $learner) {
        $id = $learner->id;
        $tasks[] = fn () => app(ScoreTotalsService::class)->recomputeLearner($id, CarbonImmutable::now());
    }
    $results = GameRace::run($tasks);

    expect(GameRace::crashes($results))->toBe([])
        ->and(collect([$results[0]['value'], $results[1]['value']])->filter()->count())->toBe(1)
        ->and(LeagueMonth::query()->findOrFail($february->id)->status)->toBe('pending_review');

    // Whatever order they ran in, one more reconcile finds nothing to write and the rows equal their source.
    app(ScoreTotalsService::class)->reconcileAll(CarbonImmutable::now()->addMinute());
    expect(atrrDrift())->toBe([])
        ->and(LearnerScoreTotal::query()->where('learner_id', $mover)->pluck('region_code')->unique()->all())->toBe(['UZ-SA'])
        ->and(LearnerScoreTotal::query()->where('learner_id', '<>', $mover)->pluck('region_code')->unique()->all())->toBe(['UZ-TK']);
});
