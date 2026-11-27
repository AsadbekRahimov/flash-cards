<?php

declare(strict_types=1);

/*
 * The statement budget of `GET /me` and `GET /today` on the second and later calls (docs/19 K9, R12i; K10) for the learner the
 * client meets on every opening of the Mini App: an active course, a session graded today, a place in the league.
 *
 *   /me:    the learner (auth), ONE statement of counts (learned, due) and - the practice being open to everybody by default -
 *           ONE statement of the day's use of the Free practice limits (`practice.limits`: sessions, battles); the plan, the groups,
 *           the subscription blocks and the course are the profile snapshot of the pure cache;
 *   /today: the learner, today + yesterday in one statement, the open sessions, ONE statement of scalars (the due cards, the Free
 *           quota, the candidates of the round of hard words, the quiz of the week), the progress over the lessons of the course and,
 *           with the game open, the runs of the game. The course row, the lapse banner, the "in a group" fact (no group - no card
 *           of a class caravan to read) and the "may have an assignment" fact (`assignments_possible`) come from the pure cache; the
 *           plan from its cache; the league line from the cached facts.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\TodayProfileCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/** @return array{learner: Learner, course: Course, headers: array<string, string>} a learner with a course, five cards graded today, placed in the league */
function todayMeScenario(object $test, string $game = 'all', bool $pro = false): array
{
    // Both ends of GAME_ACCESS are pinned below ('all' is the shipped default, 'off' the closed game); the rest is as shipped: the readiness,
    // the round of hard words and the practice with `pairs_timed` on, so the numbers are those of a production learner.
    config([
        'game.access' => $game,
        'practice.access' => 'all', 'practice.plan' => 'all', 'practice.modes.pairs_timed' => true,
        'billing.plans.free.limits.new_words_per_day' => 10,
        'readiness.enabled' => true, 'learning.hard_round.enabled' => true,
    ]);
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 25, 2 => 25]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id]);
        app(EntitlementResolver::class)->forget($learner);
    }
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id, 'source' => 'self'])->assertOk()->json();
    $card = $opened['card'];
    for ($i = 0; $i < 5; $i++) {
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json('card');
    }
    $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    // The first calls warm the per-learner entries (the profile snapshots, the course row, the league facts and standings).
    $test->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $test->withHeaders($headers)->getJson('/api/twa/today')->assertOk()->assertJsonPath('league.rank', 1);

    return ['learner' => $learner, 'course' => $course, 'headers' => $headers];
}

/** The one statement of scalars of the Today screen: the due cards first, then the rest. */
const TODAY_SCALARS = '/^select \(select count\(\*\) from ["`]word_repetitions["`].* as ["`]due["`], /';

it('answers the second /me with the learner, one statement of counts, one of the practice limits and - the game being open - one of its limits', function (string $game, int $budget): void {
    ['headers' => $h] = todayMeScenario($this, $game);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/me')->assertOk()->assertJsonStructure(['stats' => ['words_learned', 'words_due_today']]));

    // Before R12i: 7 - the groups, the live and the lapsed subscription, the course and two counts. Since the practice is open to
    // everybody (PRACTICE_ACCESS=all) the Free learner's use of the day is the third (not part of the snapshot: it changes per session).
    // GAME_ACCESS=all (the default) adds the fourth: the limits, the coins and the tickets of the game in ONE statement (`game_runs` + `game_profiles`).
    expect(count($queries))->toBe($budget)
        ->and(QueryLog::matching($queries, '/from practice_sessions/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from game_runs/'))->toHaveCount($game === 'all' ? 1 : 0)
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`](telegram_groups|subscriptions|courses)["`]/'))->toBe([]);
})->with(['the game closed' => ['off', 3], 'the game open to all (default)' => ['all', 4]]);

it('answers the second /today within a pinned number of statements, the caches warm', function (string $game, bool $pro, int $budget): void {
    ['headers' => $h] = todayMeScenario($this, $game, $pro);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('league.rank', 1)->assertJsonPath('goal.done', 5)->assertJsonPath('new_words_remaining', $pro ? null : 4) // five graded, a sixth served
        ->assertJsonPath('game.available', $game === 'all')->assertJsonPath('game.class_caravan', null)
        ->assertJsonPath('active_course.readiness.quiz.enabled', true)->assertJsonPath('active_course.readiness.quiz.this_week', null)
        ->assertJsonPath('hard_round.available', false)->assertJsonPath('hard_round.reason', 'not_enough')
        ->assertJsonPath('assignments_possible', false)); // from the snapshot: no group of a center, no statement for it

    // History: 13 before R12i (the plan six times, two day rows, two counts, five league statements); 7 after it; 8 with the quiz line
    // (docs/13 §8.5); 9 with the candidates of the round of hard words (docs/06 §14); 11 with the game open (docs/18: the runs of the day
    // and the card of the class caravan).
    // K10 (docs/19): 11 -> 6 for Free with the game open. The course row, the lapse banner and the "in a group" fact come from the pure
    // cache (no `courses`, no `subscriptions`, no `class_caravans` for a learner in no group); the quiz line and the candidates of the
    // round of hard words ride in the statement of the due cards and the quota. What is left: the learner, the day rows, the open sessions,
    // the scalars, the progress of the course and - with the game open - the runs of the game.
    // Pro pays one more: `StreakService::current()` re-reads `streak_freezes` for a learner whose goal of the day is not met and whose
    // series does not run (a concurrent freeze may have changed the stock) - not a read of K10's.
    expect(count($queries))->toBe($budget)
        ->and(QueryLog::matching($queries, '/from ["`]learners["`] where ["`]learners["`].["`]id["`] = \? and ["`]learners["`].["`]deleted_at["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]learner_daily_stats["`] where ["`]learner_id["`] = \? and ["`]date["`] >= \? and ["`]date["`] <= \?/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]training_sessions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, TODAY_SCALARS))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]word_repetitions["`] inner join ["`]words["`]/'))->toHaveCount(1) // the progress over the lessons
        ->and(QueryLog::matching($queries, '/from ["`]game_runs["`]/'))->toHaveCount($game === 'all' ? 1 : 0)
        // Folded into the scalars: the quota (Free only), the candidates of the round, the quiz of the week - each once, in that one statement.
        ->and(QueryLog::matching($queries, '/as ["`]served["`]/'))->toHaveCount($pro ? 0 : 1)
        ->and(QueryLog::matching($queries, '/\(select count\(\*\) from \(select ["`]wr["`].["`]word_id["`] from ["`]word_repetitions["`] as ["`]wr["`].* limit 10\) as ["`]hard_candidates_rows["`]\) as ["`]hard_candidates["`]/s'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]level_quiz_results["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/as ["`]quiz_correct["`], .* as ["`]quiz_total["`], .* as ["`]quiz_finished_at["`]/s'))->toHaveCount(1)
        // Not read at all: the course row, the lapse banner, the groups, the class caravan, the league tables.
        ->and(QueryLog::matching($queries, '/from ["`](courses|subscriptions|class_caravans|group_members|telegram_groups)["`]/'))->toBe([])
        ->and(QueryLog::matching($queries, '/from ["`](league_weeks|league_members|league_groups)["`]|from ["`]league_members["`] as/'))->toBe([])
        ->and(QueryLog::matching($queries, '/^(insert|update|delete)/'))->toBe([]);
})->with([
    'Free, the game closed' => ['off', false, 5],
    'Free, the game open to all (default)' => ['all', false, 6],
    'Pro, the game closed' => ['off', true, 6],
    'Pro, the game open to all (default)' => ['all', true, 7],
]);

it('pays one statement for the course row and one for the lapse banner of Free when the pure cache is cold, and folds the groups into the scalars', function (string $game, bool $pro, int $budget): void {
    ['learner' => $learner, 'course' => $course, 'headers' => $h] = todayMeScenario($this, $game, $pro);
    // The two entries of K10 are gone (an eviction, a restart of `redis-cache`); the plan and the league stay warm.
    PureCache::store()->forget(TodayProfileCache::key($learner, EntitlementResolver::version($learner->id)));
    PureCache::store()->forget(CourseCatalogService::courseRowKey($course->id));

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->assertJsonPath('active_course.id', $course->id)->assertJsonPath('entitlement.lapsed', null));

    // The worst case of K10 is the budget before it minus the folded reads: + the course row (`courses`), + the lapse lookup (Free only),
    // and the membership count rides in the statement of scalars (no statement of its own).
    expect(count($queries))->toBe($budget)
        ->and(QueryLog::matching($queries, '/from ["`]courses["`] where ["`]courses["`].["`]id["`] = \?/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]subscriptions["`].*order by ["`]ended_at["`] desc/'))->toHaveCount($pro ? 0 : 1)
        ->and(QueryLog::matching($queries, '/\(select 1 from ["`]group_members["`] as ["`]gm["`] inner join ["`]telegram_groups["`] as ["`]tg["`].*\) as ["`]groups["`]/s'))->toHaveCount(1)
        // "may have an assignment" (`assignments_possible`) rides in the same statement of scalars, beside the groups.
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`].*\) as ["`]groups["`], \(select 1 from ["`]group_members["`] as ["`]gm["`].*\) as ["`]assignments["`]/s'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, TODAY_SCALARS))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]class_caravans["`]/'))->toBe([])
        ->and(PureCache::store()->has(TodayProfileCache::key($learner, EntitlementResolver::version($learner->id))))->toBeTrue()
        ->and(PureCache::store()->has(CourseCatalogService::courseRowKey($course->id)))->toBeTrue();

    // Warm again: back to the pinned budget.
    expect(count(QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk())))->toBe($budget - ($pro ? 1 : 2));
})->with([
    'Free, the game closed' => ['off', false, 7],
    'Free, the game open to all (default)' => ['all', false, 8],
    'Pro, the game closed' => ['off', true, 7],
    'Pro, the game open to all (default)' => ['all', true, 8],
]);

it('reads the plan once per /today when its cache is cold', function (): void {
    ['learner' => $learner, 'headers' => $h] = todayMeScenario($this);
    app(EntitlementResolver::class)->forget($learner);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk());

    expect(QueryLog::matching($queries, '/from ["`]subscriptions["`].*organization_members/'))->toHaveCount(1);
});

it('answers the same /today from the warm caches as from the cold ones', function (bool $pro): void {
    ['learner' => $learner, 'course' => $course, 'headers' => $h] = todayMeScenario($this, 'all', $pro);
    $warm = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json();

    PureCache::store()->forget(TodayProfileCache::key($learner, EntitlementResolver::version($learner->id)));
    PureCache::store()->forget(CourseCatalogService::courseRowKey($course->id));
    $cold = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json();

    expect($cold)->toBe($warm);
})->with(['Free' => [false], 'Pro' => [true]]);

it('pays nothing for `assignments_possible`: a member of a center\'s group answers true within the same budget', function (bool $pro, int $budget): void {
    ['learner' => $learner, 'headers' => $h] = todayMeScenario($this, 'all', $pro);
    // A switched-off group of a center: the list of assignments still looks at it (true), the class caravan does not (no card to read),
    // so the statements are those of the learner in no group.
    $group = TelegramGroup::factory()->create(['organization_id' => Organization::factory()->create()->id, 'status' => 'disabled']);
    app(GroupMembershipService::class)->join($learner, $group, 'test');
    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', true); // the snapshot anew

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', true));

    expect(count($queries))->toBe($budget)
        ->and(QueryLog::matching($queries, '/from ["`](group_members|telegram_groups|assignments)["`]/'))->toBe([]);
})->with(['Free' => [false, 6], 'Pro' => [true, 7]]);
