<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\StreakService;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * docs/12 §1.6, §3.1, §7.4 — the day aggregate written inside the review transaction.
 */

uses(RefreshDatabase::class);

function statsLearner(int $goal = 20, string $tz = 'Asia/Tashkent'): Learner
{
    return Learner::factory()->create(['daily_goal' => $goal, 'timezone' => $tz]);
}

function at(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** Grades `$n` cards in one go (each in its own transaction, like real requests). */
function grade(Learner $learner, int $n, string $utc, int $quality = 4, bool $new = false, int $ms = 1000): LearnerDailyStats
{
    $row = null;
    for ($i = 0; $i < $n; $i++) {
        $row = DB::transaction(fn (): LearnerDailyStats => app(DailyStatsService::class)->recordReview($learner, $quality, $ms, $new, at($utc)));
    }

    /** @var LearnerDailyStats $row */
    return $row;
}

it('creates the day row on the first grade and increments every counter afterwards', function (): void {
    $learner = statsLearner();

    $first = grade($learner, 1, '2026-10-18 10:00:00', quality: 5, new: true, ms: 1500);
    expect($first->date->toDateString())->toBe('2026-10-18')
        ->and($first->reviews_count)->toBe(1)
        ->and($first->correct_count)->toBe(1)
        ->and($first->new_words_count)->toBe(1)
        ->and($first->time_spent_ms)->toBe(1500)
        ->and($first->goal_met_at)->toBeNull();

    grade($learner, 1, '2026-10-18 10:01:00', quality: 2, new: false, ms: 700);   // wrong, not new
    $third = grade($learner, 1, '2026-10-18 10:02:00', quality: 3, new: true, ms: 300); // borderline correct

    expect(LearnerDailyStats::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and($third->reviews_count)->toBe(3)
        ->and($third->correct_count)->toBe(2)
        ->and($third->new_words_count)->toBe(2)
        ->and($third->time_spent_ms)->toBe(2500);
});

it('uses the learner local date: 23:59 and 00:01 in Tashkent are two rows while UTC date is the same', function (): void {
    $learner = statsLearner();

    grade($learner, 2, '2026-10-18 18:59:00'); // 23:59 local
    grade($learner, 3, '2026-10-18 19:01:00'); // 00:01 local next day

    $rows = LearnerDailyStats::query()->where('learner_id', $learner->id)->orderBy('date')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->date->toDateString())->toBe('2026-10-18')
        ->and($rows[0]->reviews_count)->toBe(2)
        ->and($rows[1]->date->toDateString())->toBe('2026-10-19')
        ->and($rows[1]->reviews_count)->toBe(3);

    // A UTC learner at the same instants stays on one day.
    $utcLearner = statsLearner(tz: 'UTC');
    grade($utcLearner, 1, '2026-10-18 18:59:00');
    grade($utcLearner, 1, '2026-10-18 19:01:00');
    expect(LearnerDailyStats::query()->where('learner_id', $utcLearner->id)->count())->toBe(1);
});

it('keeps one row per local day across a DST transition in Europe/Berlin', function (): void {
    $learner = statsLearner(tz: 'Europe/Berlin');

    grade($learner, 1, '2026-03-29 00:30:00'); // 01:30 CET
    grade($learner, 1, '2026-03-29 21:59:00'); // 23:59 CEST — same local day, 23 h after the first
    grade($learner, 1, '2026-03-29 22:00:00'); // 00:00 CEST next day

    $rows = LearnerDailyStats::query()->where('learner_id', $learner->id)->orderBy('date')->get();
    expect($rows->map(fn (LearnerDailyStats $r): array => [$r->date->toDateString(), $r->reviews_count])->all())
        ->toBe([['2026-03-29', 2], ['2026-03-30', 1]]);
});

it('sets goal_met_at exactly once when reviews_count reaches daily_goal and leaves it untouched afterwards', function (): void {
    $learner = statsLearner(goal: 3);

    grade($learner, 2, '2026-10-18 10:00:00');
    expect(app(DailyStatsService::class)->today($learner, at('2026-10-18 10:00:00'))?->goal_met_at)->toBeNull();

    $met = grade($learner, 1, '2026-10-18 10:05:00');
    expect($met->goal_met_at?->toDateTimeString())->toBe('2026-10-18 10:05:00');

    $later = grade($learner, 5, '2026-10-18 12:00:00');
    expect($later->reviews_count)->toBe(8)
        ->and($later->goal_met_at?->toDateTimeString())->toBe('2026-10-18 10:05:00');

    $learner->refresh();
    expect($learner->streak_current)->toBe(1)
        ->and($learner->streak_best)->toBe(1)
        ->and($learner->streak_last_date?->toDateString())->toBe('2026-10-18')
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(1);
});

it('notifies StreakService once and only when the goal is first met', function (): void {
    $learner = statsLearner(goal: 2);

    $dates = [];
    $this->mock(StreakService::class, function ($mock) use (&$dates): void {
        $mock->shouldReceive('onGoalMet')->once()->andReturnUsing(function (Learner $l, CarbonImmutable $date) use (&$dates): void {
            $dates[] = $date->toDateString();
        });
    });

    grade($learner, 4, '2026-10-18 10:00:00');

    expect($dates)->toBe(['2026-10-18']);
});

it('treats a daily_goal of 0 as "met on the first review" instead of never', function (): void {
    $learner = statsLearner(goal: 0);

    expect(grade($learner, 1, '2026-10-18 10:00:00')->goal_met_at)->not->toBeNull();
});

it('does not project the streak onto learners until the review transaction commits, and never on rollback', function (): void {
    $learner = statsLearner(goal: 1);

    DB::transaction(function () use ($learner): void {
        app(DailyStatsService::class)->recordReview($learner, 5, 100, true, at('2026-10-18 10:00:00'));

        // Inside the transaction: the day row says "met", learners is still untouched (no lock on it).
        expect(LearnerDailyStats::query()->where('learner_id', $learner->id)->value('goal_met_at'))->not->toBeNull()
            ->and((int) Learner::query()->whereKey($learner->id)->value('streak_current'))->toBe(0);
    });

    expect((int) Learner::query()->whereKey($learner->id)->value('streak_current'))->toBe(1);

    $other = statsLearner(goal: 1);
    try {
        DB::transaction(function () use ($other): void {
            app(DailyStatsService::class)->recordReview($other, 5, 100, true, at('2026-10-18 10:00:00'));
            throw new RuntimeException('grade failed after the hook');
        });
    } catch (RuntimeException) {
    }

    expect(LearnerDailyStats::query()->where('learner_id', $other->id)->exists())->toBeFalse()
        ->and((int) Learner::query()->whereKey($other->id)->value('streak_current'))->toBe(0)
        ->and(ProductEvent::query()->where('learner_id', $other->id)->exists())->toBeFalse();
});

it('reads today() and forDate() by the learner local date', function (): void {
    $learner = statsLearner();
    $service = app(DailyStatsService::class);

    grade($learner, 2, '2026-10-18 18:59:00'); // local 2026-10-18

    expect($service->today($learner, at('2026-10-18 18:59:59'))?->reviews_count)->toBe(2)
        ->and($service->today($learner, at('2026-10-18 19:00:00')))->toBeNull()
        ->and($service->forDate($learner, '2026-10-18')?->reviews_count)->toBe(2)
        ->and($service->forDate($learner, '2026-10-19'))->toBeNull();
});

it('coexists with factory-created rows of the same day (one row per unique key)', function (): void {
    $learner = statsLearner();
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-18', 'reviews_count' => 7]);

    $row = grade($learner, 1, '2026-10-18 10:00:00');

    expect($row->reviews_count)->toBe(8)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('is driven by the real review flow: a first grade counts as a new word, a re-grade does not', function (): void {
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);
    Word::factory()->for($lesson)->create();

    $learner = statsLearner(goal: 2);
    $session = app(TrainingSessionService::class)->openPersonal($learner, 'lesson', $lesson);
    $flow = app(TrainingFlowService::class);

    $word = $flow->nextStep($session, $learner)->word;
    $flow->review($session, $learner, (int) $word?->id, 2, 1500);              // first grade: new card, wrong
    $token = $flow->nextStep($session, $learner)->serveToken;                  // learn-ahead re-serve
    $flow->review($session, $learner, (int) $word?->id, 4, 800, $token);      // second grade → goal of 2 met

    $row = app(DailyStatsService::class)->today($learner);
    expect($row?->reviews_count)->toBe(2)
        ->and($row?->correct_count)->toBe(1)
        ->and($row?->new_words_count)->toBe(1)
        ->and($row?->time_spent_ms)->toBe(2300)
        ->and($row?->goal_met_at)->not->toBeNull();

    $state = app(StreakService::class)->current($learner->refresh(), app(LearnerClock::class)->today($learner));
    expect($state->current)->toBe(1)
        ->and($state->metToday)->toBeTrue()
        ->and($state->atRisk)->toBeFalse();
});
