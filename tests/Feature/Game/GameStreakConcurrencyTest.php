<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;

/*
 * docs/18 §12.6 (G2.10): the finish of a run and a grade that meets the goal on the same local day, at the same instant. Both hold
 * the row of the day FOR UPDATE, so each sees the other's fact; after the commits two `StreakService::apply` of one date (Game and
 * Goal) meet on the optimistic `UPDATE learners … WHERE streak_last_date = <seen>`, and two `LeagueService::place` on the unique
 * `(week, learner)`. The day is counted once, the learner is seated once, `daily_goal.met` is told once. PostgreSQL + pcntl only
 * (the harness: Tests\Support\Game\GameRace).
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

    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

it('counts the day once when the last answer of a run and the grade that meets the goal land together: one +1, one seat, one daily_goal.met', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learner->forceFill(['daily_goal' => 1, 'streak_current' => 2, 'streak_best' => 2, 'streak_last_date' => '2026-11-04'])->save();
    $learnerId = $learner->id;
    $now = CarbonImmutable::now();

    // A due card of the lesson and an open session to grade it in: the one grade meets the goal of 1.
    $word = Word::factory()->for($lesson)->create(['word' => 'racecard', 'translation' => 'карта гонки']);
    WordRepetition::factory()->create([
        'learner_id' => $learnerId, 'word_id' => $word->id, 'state' => 'review', 'repetitions' => 3, 'interval_days' => 10, 'easiness_factor' => 2.5,
        'last_reviewed_at' => $now->subDays(10), 'next_review_at' => $now->subHour(), 'introduced_mode' => 'lesson', 'stability' => null, 'difficulty' => null,
    ]);
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);
    $sessionId = $session->id;
    $wordId = $word->id;

    // The run, played to its last element in the parent.
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    $runId = $run->id;
    $answer = GameKit::rightAnswers($last)[0];

    $results = GameRace::run([
        fn (): string => app(RunService::class)->answer($runId, GameRace::learner($learnerId), (string) Str::uuid(), $last['id'], $answer)->run->status->value,
        fn (): int => app(ReviewSubmissionService::class)->submit(TrainingSession::query()->findOrFail($sessionId), GameRace::learner($learnerId), $wordId, 4, 1500, null, $now)->repetitions,
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['ok', 'ok'])
        ->and($results[0]['value'])->toBe('finished')
        ->and($results[1]['value'])->toBe(4);

    $fresh = Learner::query()->findOrFail($learnerId);
    expect([$fresh->streak_current, $fresh->streak_best, $fresh->streak_last_date?->toDateString()])->toBe([3, 3, '2026-11-05']);

    $day = LearnerDailyStats::query()->where('learner_id', $learnerId)->get();
    expect($day)->toHaveCount(1)
        ->and($day[0]->game_runs_count)->toBe(1)->and($day[0]->game_held_at)->not->toBeNull()
        ->and($day[0]->reviews_count)->toBe(1)->and($day[0]->goal_met_at)->not->toBeNull();

    expect(LeagueMember::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(GameRace::events(ProductEventName::LeaguePlaced->value))->toHaveCount(1)
        ->and(GameRace::events(ProductEventName::DailyGoalMet->value))->toHaveCount(1)
        ->and(GameRace::events(ProductEventName::StreakExtended->value))->toHaveCount(1)
        ->and(GameRace::events(ProductEventName::GameRunFinished->value))->toHaveCount(1);
});
