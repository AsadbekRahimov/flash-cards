<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learners\Enums\StreakHeldBy;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\StreakService;
use App\Models\Achievement;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.6 (G2.10): the streak and the league of the game. A finished run (completed or failed) holds the local day of its start
 * and counts +1 like a goal day; a freeze still only bridges; the goal and a run on one day count once; the game never writes
 * `daily_goal.met`. The league adds the capped `game_points` of the day to the points of the cards (one cap of 40 for Free and Pro) and
 * seats a learner whose first activity of the day was a run. `/today.streak.held_by` names what held today.
 *
 * The clock is 2026-11-05 10:00 UTC = 15:00 in Tashkent (the default zone of a learner), a Thursday of the league week of Monday 11-02.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A whole run of the stop at the current test clock: completed, or failed by four wrong answers (three flasks lost). */
function glsRun(Learner $learner, Course $course, Lesson $lesson, bool $fail = false): GameRun
{
    $runs = app(RunService::class);

    return GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner, wrongAt: $fail ? [0, 1, 2, 3] : []);
}

/** @return array{int, int, string|null} [current, best, last] as stored */
function glsStreak(Learner $learner): array
{
    $fresh = Learner::query()->findOrFail($learner->id);

    return [$fresh->streak_current, $fresh->streak_best, $fresh->streak_last_date?->toDateString()];
}

function glsEvents(ProductEventName $name): int
{
    return ProductEvent::query()->where('name', $name->value)->count();
}

/** Meets the goal of the day (`daily_goal` grades) the way POST /review does it: each grade in its own transaction, hooks after commit. */
function glsMeetGoal(Learner $learner, ?CarbonImmutable $now = null): void
{
    $now ??= CarbonImmutable::now();
    for ($i = 0; $i < max(1, $learner->daily_goal); $i++) {
        DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, $now));
    }
}

function glsToday(Learner $learner): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/today')->assertOk();
}

function glsDayRow(Learner $learner, string $date): LearnerDailyStats
{
    return LearnerDailyStats::query()->where('learner_id', $learner->id)->whereDate('date', $date)->firstOrFail();
}

function glsPro(Learner $learner): Learner
{
    Subscription::factory()->create(['subscriber_id' => $learner->id]);

    return $learner;
}

/** Another learner on the same stop (the kit makes one course with a fixed slug): the words of the lesson introduced to them. */
function glsAnother(Lesson $lesson, array $attributes = []): Learner
{
    $learner = Learner::factory()->create($attributes);
    foreach (Word::query()->where('lesson_id', $lesson->id)->get() as $word) {
        PracticeKit::introduce($learner, $word, 'review');
    }

    return $learner;
}

// --- the streak ---------------------------------------------------------------------

it('holds the day with a completed run and counts it +1 like a goal day: no daily_goal.met, streak.extended once the series continues', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);

    $run = glsRun($learner, $course, $lesson);
    expect($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and(glsStreak($learner))->toBe([1, 1, '2026-11-05'])
        ->and($learner->streak_current)->toBe(1) // the passed model is refreshed by the hook, as after a goal
        ->and(glsEvents(ProductEventName::DailyGoalMet))->toBe(0)
        ->and(glsEvents(ProductEventName::StreakExtended))->toBe(0);

    // A second run the same day changes nothing of the series.
    CarbonImmutable::setTestNow('2026-11-05 10:30:00');
    glsRun($learner, $course, $lesson);
    expect(glsStreak($learner))->toBe([1, 1, '2026-11-05']);

    CarbonImmutable::setTestNow('2026-11-06 10:00:00');
    glsRun($learner, $course, $lesson);
    expect(glsStreak($learner))->toBe([2, 2, '2026-11-06'])
        ->and(glsEvents(ProductEventName::DailyGoalMet))->toBe(0)
        ->and(glsEvents(ProductEventName::StreakExtended))->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::StreakExtended->value)->firstOrFail()->props)->toMatchArray(['current' => 2, 'best' => 2]);
});

it('tells the summary whether this finish held the day of the streak and how many stars the stop gained (G2.13): the first run of a day, a day the goal held first, a second run, a run without a finish', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learner->update(['timezone' => 'UTC']);
    $runs = app(RunService::class);
    $summary = fn (GameRun $run): array => $runs->view($run->refresh(), $learner)->summary ?? [];

    // The first run of the day holds it (StreakService::apply(Game) is called on exactly this) and clears the stop: three new stars.
    $first = glsRun($learner, $course, $lesson);
    expect($summary($first))->toMatchArray(['streak_held' => true, 'new_stars' => 3, 'stars' => 3])
        ->and($first->state['streak_held'])->toBeTrue()->and($first->state['new_stars'])->toBe(3)
        ->and(glsStreak($learner))->toBe([1, 1, '2026-11-05']);

    // A second run of the same day: the day is held already, the stop has its three stars - nothing new.
    $second = glsRun($learner, $course, $lesson, fail: true);
    expect($summary($second))->toMatchArray(['streak_held' => false, 'new_stars' => 0, 'stars' => 0]);

    // The next day the goal comes first: the run held nothing (the goal's own hook counted the day), and one star over yesterday's... none.
    CarbonImmutable::setTestNow('2026-11-06 10:00:00');
    glsMeetGoal($learner);
    $afterGoal = glsRun($learner, $course, $lesson);
    expect($summary($afterGoal))->toMatchArray(['streak_held' => false, 'new_stars' => 0])->and(glsStreak($learner))->toBe([2, 2, '2026-11-06']);

    // A run that gained one star over a best of one: `new_stars` is the difference, not the stars.
    GameStopProgress::query()->where('learner_id', $learner->id)->update(['stars' => 1]);
    CarbonImmutable::setTestNow('2026-11-07 10:00:00');
    $better = glsRun($learner, $course, $lesson, fail: false);
    expect($summary($better))->toMatchArray(['streak_held' => true, 'new_stars' => 2, 'stars' => 3]);

    // A run closed without a finish (abandoned) held no day and gained nothing - and says so, as its summary is read from the state.
    $left = $runs->start($learner, $course->slug, $lesson->id);
    $runs->abandon($left, $learner);
    expect($summary($left))->toMatchArray(['streak_held' => false, 'new_stars' => 0, 'stars' => null]);
});

it('holds the day with a failed run as well (the owner\'s decision В1): +1, like the completed one', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learner->forceFill(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-11-04'])->save();

    $run = glsRun($learner, $course, $lesson, fail: true);

    expect($run->finish_reason)->toBe(RunFinishReason::Failed)
        ->and(glsStreak($learner))->toBe([4, 4, '2026-11-05'])
        ->and(glsEvents(ProductEventName::StreakExtended))->toBe(1)
        ->and(glsEvents(ProductEventName::DailyGoalMet))->toBe(0);
});

it('keeps the streak of a run that started before local midnight and finished after it on the day of the start (its local_date)', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    CarbonImmutable::setTestNow('2026-11-05 18:55:00'); // 23:55 in Tashkent
    $run = $runs->start($learner, $course->slug, $lesson->id);
    CarbonImmutable::setTestNow('2026-11-05 19:05:00'); // 00:05 of the 6th
    $run = GameKit::play($runs, $run, $learner);

    expect($run->local_date->toDateString())->toBe('2026-11-05')
        ->and(glsStreak($learner))->toBe([1, 1, '2026-11-05'])
        ->and(glsDayRow($learner, '2026-11-05')->game_held_at)->not->toBeNull();
});

it('counts a day with both the goal and a run once, whichever came first, and still tells daily_goal.met exactly once', function (): void {
    // The run first: the goal met later finds the day counted and only writes its own fact.
    [$gameFirst, $course, $lesson] = GameKit::stop(8);
    $gameFirst->forceFill(['daily_goal' => 2])->save();
    glsRun($gameFirst, $course, $lesson);
    expect(glsStreak($gameFirst))->toBe([1, 1, '2026-11-05']);

    glsMeetGoal($gameFirst);
    $met = ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->where('learner_id', $gameFirst->id)->get();
    expect(glsStreak($gameFirst))->toBe([1, 1, '2026-11-05'])
        ->and($met)->toHaveCount(1)
        ->and($met[0]->props)->toMatchArray(['streak' => 1, 'date' => '2026-11-05'])
        ->and(glsEvents(ProductEventName::StreakExtended))->toBe(0)
        ->and(glsToday($gameFirst)->json('streak'))->toMatchArray(['current' => 1, 'met_today' => true, 'at_risk' => false, 'held_by' => 'goal']);

    // The goal first: the finish sees goal_met_at set and leaves the streak to the goal's own hook.
    $goalFirst = glsAnother($lesson, ['daily_goal' => 2]);
    glsMeetGoal($goalFirst);
    expect(glsStreak($goalFirst))->toBe([1, 1, '2026-11-05']);

    glsRun($goalFirst, $course, $lesson);
    expect(glsStreak($goalFirst))->toBe([1, 1, '2026-11-05'])
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->where('learner_id', $goalFirst->id)->count())->toBe(1)
        ->and(glsDayRow($goalFirst, '2026-11-05')->game_held_at)->not->toBeNull()
        ->and(glsToday($goalFirst)->json('streak.held_by'))->toBe('goal');
});

it('spends a freeze on the one missed day before a run, as before a goal: the frozen day bridges, the run counts', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    glsPro($learner)->forceFill(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-11-03', 'streak_freezes' => 1])->save();

    glsRun($learner, $course, $lesson);

    expect(glsStreak($learner))->toBe([4, 4, '2026-11-05'])
        ->and(Learner::query()->findOrFail($learner->id)->streak_freezes)->toBe(0)
        ->and(glsDayRow($learner, '2026-11-04')->frozen_at)->not->toBeNull()
        ->and(glsEvents(ProductEventName::StreakFrozen))->toBe(1)
        ->and(glsEvents(ProductEventName::StreakExtended))->toBe(1)
        ->and(glsEvents(ProductEventName::DailyGoalMet))->toBe(0);
});

it('restarts at 1 after a missed day without a freeze, and rebuilds the series from game-held days when the projection lagged', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    // Three days held by the game whose callbacks were lost (the projection never moved), then a day missed.
    foreach (['2026-10-31', '2026-11-01', '2026-11-02'] as $date) {
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'game_runs_count' => 1, 'game_points' => 16, 'game_held_at' => CarbonImmutable::parse($date.' 10:00:00', 'UTC')]);
    }
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-11-04', 'game_runs_count' => 1, 'game_points' => 16, 'game_held_at' => CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')]);

    glsRun($learner, $course, $lesson);

    // The rebuild from the facts: 11-04 (game) + 11-05 (this run) = 2; the three days before the gap are not reachable.
    expect(glsStreak($learner))->toBe([2, 2, '2026-11-05']);

    // A series of game days seen on read: the lost callback of yesterday is repaired, no freeze is wanted for a held day.
    $other = glsPro(glsAnother($lesson, ['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-11-03', 'streak_freezes' => 2]));
    LearnerDailyStats::factory()->create(['learner_id' => $other->id, 'date' => '2026-11-04', 'game_runs_count' => 1, 'game_points' => 16, 'game_held_at' => CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')]);

    $state = app(StreakService::class)->current($other, app(LearnerClock::class)->today($other, CarbonImmutable::now()), null, CarbonImmutable::now());
    expect($state->toArray())->toBe(['current' => 4, 'best' => 4, 'met_today' => false, 'at_risk' => true, 'freezes' => 2, 'frozen_yesterday' => false, 'held_by' => null])
        ->and(glsStreak($other))->toBe([4, 4, '2026-11-04'])
        ->and(glsEvents(ProductEventName::StreakFrozen))->toBe(0);
});

it('does not freeze a day the game held when a read and a finish disagree about it: the freeze claim checks game_held_at', function (): void {
    [$learner] = GameKit::stop(8);
    glsPro($learner)->forceFill(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-11-03', 'streak_freezes' => 1])->save();
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-11-04', 'game_runs_count' => 1, 'game_held_at' => CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')]);

    $spent = app(StreakFreezeService::class)->tryFreezeMany($learner, ['2026-11-04'], CarbonImmutable::now());

    expect($spent)->toBeFalse()
        ->and(Learner::query()->findOrFail($learner->id)->streak_freezes)->toBe(1)
        ->and(glsDayRow($learner, '2026-11-04')->frozen_at)->toBeNull();
});

it('refuses to apply a frozen day as a credit', function (): void {
    $learner = Learner::factory()->create();

    expect(fn () => app(StreakService::class)->apply($learner, '2026-11-05', CarbonImmutable::now(), StreakHeldBy::Freeze))->toThrow(LogicException::class);
});

it('names what held a day: the goal wins over the game, then the game, then a freeze', function (): void {
    $goal = new LearnerDailyStats(['goal_met_at' => CarbonImmutable::now(), 'game_held_at' => CarbonImmutable::now(), 'frozen_at' => CarbonImmutable::now()]);
    $game = new LearnerDailyStats(['game_held_at' => CarbonImmutable::now(), 'frozen_at' => CarbonImmutable::now()]);
    $freeze = new LearnerDailyStats(['frozen_at' => CarbonImmutable::now()]);
    $none = new LearnerDailyStats;

    expect(StreakHeldBy::fromRow($goal))->toBe(StreakHeldBy::Goal)
        ->and(StreakHeldBy::fromRow($game))->toBe(StreakHeldBy::Game)
        ->and(StreakHeldBy::fromRow($freeze))->toBe(StreakHeldBy::Freeze)
        ->and(StreakHeldBy::fromRow($none))->toBeNull()
        ->and(StreakHeldBy::Goal->counts())->toBeTrue()
        ->and(StreakHeldBy::Game->counts())->toBeTrue()
        ->and(StreakHeldBy::Freeze->counts())->toBeFalse();
});

// --- /today.streak.held_by --------------------------------------------------------------

it('tells /today what held the day: null, goal, game or freeze', function (): void {
    [$byGame, $course, $lesson] = GameKit::stop(8);
    glsRun($byGame, $course, $lesson);
    expect(glsToday($byGame)->json('streak'))->toMatchArray(['current' => 1, 'met_today' => true, 'at_risk' => false, 'held_by' => 'game'])
        ->and(glsToday($byGame)->json('goal.met'))->toBeFalse(); // the goal of cards is its own thing

    $fresh = glsAnother($lesson);
    expect(glsToday($fresh)->json('streak'))->toMatchArray(['current' => 0, 'met_today' => false, 'held_by' => null]);

    $byGoal = glsAnother($lesson, ['daily_goal' => 2]);
    glsMeetGoal($byGoal);
    expect(glsToday($byGoal)->json('streak'))->toMatchArray(['current' => 1, 'met_today' => true, 'at_risk' => false, 'held_by' => 'goal']);

    $byFreeze = glsAnother($lesson, ['streak_current' => 2, 'streak_best' => 2, 'streak_last_date' => '2026-11-05']);
    LearnerDailyStats::factory()->create(['learner_id' => $byFreeze->id, 'date' => '2026-11-05', 'frozen_at' => CarbonImmutable::now()]);
    expect(glsToday($byFreeze)->json('streak'))->toMatchArray(['current' => 2, 'met_today' => true, 'held_by' => 'freeze']);

    // Yesterday held by the game, nothing today: at risk, nothing named.
    $yesterday = glsAnother($lesson, ['streak_current' => 1, 'streak_best' => 1, 'streak_last_date' => '2026-11-04']);
    expect(glsToday($yesterday)->json('streak'))->toMatchArray(['current' => 1, 'met_today' => false, 'at_risk' => true, 'held_by' => null]);
});

it('repairs on /today a day held by the game whose hook was lost, and names it', function (): void {
    [$learner] = GameKit::stop(8);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-11-05', 'game_runs_count' => 1, 'game_points' => 16, 'game_held_at' => CarbonImmutable::now()]);

    expect(glsToday($learner)->json('streak'))->toMatchArray(['current' => 1, 'best' => 1, 'met_today' => true, 'held_by' => 'game'])
        ->and(glsStreak($learner))->toBe([1, 1, '2026-11-05'])
        ->and(glsEvents(ProductEventName::DailyGoalMet))->toBe(0);
});

// --- achievements -------------------------------------------------------------------------

it('evaluates the counting achievements when a run holds the day: streak_7 unlocks on the seventh game day', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learner->forceFill(['streak_current' => 6, 'streak_best' => 6, 'streak_last_date' => '2026-11-04'])->save();

    glsRun($learner, $course, $lesson);

    expect(glsStreak($learner))->toBe([7, 7, '2026-11-05'])
        ->and(Achievement::query()->where('learner_id', $learner->id)->pluck('key')->all())->toBe(['streak_7']);

    // A second run of the day holds nothing new: no second look, nothing twice.
    CarbonImmutable::setTestNow('2026-11-05 10:30:00');
    glsRun($learner, $course, $lesson);
    expect(Achievement::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

// --- the league -------------------------------------------------------------------------------

it('seats a learner whose first activity of the day was a run, once, and not when leagues are off or the learner opted out', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $leagues = app(LeagueService::class);

    glsRun($learner, $course, $lesson);
    $week = $leagues->findWeek(CarbonImmutable::now());
    expect($week)->not->toBeNull()
        ->and($week?->starts_on->toDateString())->toBe('2026-11-02')
        ->and(LeagueMember::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(glsEvents(ProductEventName::LeaguePlaced))->toBe(1);

    CarbonImmutable::setTestNow('2026-11-05 10:30:00');
    glsRun($learner, $course, $lesson);
    glsMeetGoal($learner);
    expect(LeagueMember::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(glsEvents(ProductEventName::LeaguePlaced))->toBe(1);

    // A grade first, then a run: the grade seated the learner; the finish has nothing to do.
    $graded = glsAnother($lesson);
    glsMeetGoal($graded);
    glsRun($graded, $course, $lesson);
    expect(LeagueMember::query()->where('learner_id', $graded->id)->count())->toBe(1);

    $optedOut = glsAnother($lesson, ['meta' => [LeagueService::OPT_OUT_META => true]]);
    glsRun($optedOut, $course, $lesson);
    expect(LeagueMember::query()->where('learner_id', $optedOut->id)->count())->toBe(0)
        ->and(glsStreak($optedOut))->toBe([1, 1, '2026-11-05']); // the streak does not depend on the league

    config(['leagues.enabled' => false]);
    $off = glsAnother($lesson);
    glsRun($off, $course, $lesson);
    expect(LeagueMember::query()->where('learner_id', $off->id)->count())->toBe(0);
});

it('adds the capped game points of the day to the points of the cards, and shows them on /today', function (): void {
    config(['cache.pure_store' => 'array']);
    [$learner, $course, $lesson] = GameKit::stop(8);
    $leagues = app(LeagueService::class);
    $now = CarbonImmutable::now();

    // Cards: 10 reviews, 8 correct, 2 new, the goal met = 10 + 8 + 4 + 10 = 32.
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-11-03', 'reviews_count' => 10, 'correct_count' => 8, 'new_words_count' => 2, 'goal_met_at' => $now]);
    $member = $leagues->place($learner, $now);
    expect($member)->not->toBeNull();

    $run = glsRun($learner, $course, $lesson, fail: true);
    $day = glsDayRow($learner, '2026-11-05');
    expect($day->game_points)->toBe($run->league_points)->and($run->league_points)->toBeGreaterThan(0);

    $group = $member?->group;
    expect($group)->not->toBeNull();
    $standings = $leagues->standings($group, $now);
    expect($standings)->toHaveCount(1)
        ->and($standings[0]->points)->toBe(32 + $run->league_points)
        ->and(glsToday($learner)->json('league.points'))->toBe(32 + $run->league_points);
});

it('caps the game at 40 league points a day for Free and Pro alike: the unlimited runs of Pro buy no point in the league', function (): void {
    config(['cache.pure_store' => 'array']);
    [$free, $course, $lesson] = GameKit::stop(8);
    $pro = glsPro(glsAnother($lesson));
    $leagues = app(LeagueService::class);
    $now = CarbonImmutable::now();

    $freeMember = $leagues->place($free, $now);
    $proMember = $leagues->place($pro, $now);
    expect($freeMember?->league_group_id)->toBe($proMember?->league_group_id);

    // Free: three faultless runs reach the cap (each run is 8-10 elements, 2 points each); Pro: five.
    foreach ([1, 2, 3] as $i) {
        CarbonImmutable::setTestNow("2026-11-05 10:0{$i}:00");
        glsRun($free, $course, $lesson);
    }
    foreach ([1, 2, 3, 4, 5] as $i) {
        CarbonImmutable::setTestNow("2026-11-05 11:0{$i}:00");
        glsRun($pro, $course, $lesson);
    }

    expect(glsDayRow($free, '2026-11-05')->game_points)->toBe(40)
        ->and(glsDayRow($pro, '2026-11-05')->game_points)->toBe(40)
        ->and(GameRun::query()->where('learner_id', $pro->id)->orderByDesc('id')->value('league_points'))->toBe(0)
        ->and(GameRun::query()->where('learner_id', $pro->id)->sum('league_points'))->toBe(40);

    $group = $freeMember?->group;
    expect($group)->not->toBeNull();
    $points = $leagues->standings($group, CarbonImmutable::now())->mapWithKeys(fn ($s): array => [$s->learnerId => $s->points])->all();
    expect($points)->toBe([$free->id => 40, $pro->id => 40]);
});

it('reads the standings with the game under a v2 key, so what v1 cached is never shown', function (): void {
    expect(LeagueService::CACHE_PREFIX)->toBe('league:standings:v2:');
});
